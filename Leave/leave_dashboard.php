<?php
declare(strict_types=1);

require_once __DIR__ . '/leave_helpers.php';

$input = bp_input();
$staffId = bp_str($input, 'staff_unique_id', bp_str($input, 'employee_id'));

if ($staffId === '') {
    bp_send_json([
        'status' => false,
        'message' => 'staff_unique_id or employee_id is required',
    ], 400);
}

$staff = bp_fetch_staff($staffId);
if (!$staff) {
    bp_send_json([
        'status' => false,
        'message' => 'Employee not found',
    ], 404);
}

$employeeId = trim((string)($staff['employee_id'] ?? ''));
if ($employeeId === '') {
    bp_send_json([
        'status' => false,
        'message' => 'Employee id mapping failed',
    ], 500);
}

$calendarFromDate = bp_date_ymd(bp_str($input, 'calendar_from_date'));
$calendarToDate = bp_date_ymd(bp_str($input, 'calendar_to_date'));
if ($calendarFromDate === null || $calendarToDate === null || $calendarFromDate > $calendarToDate) {
    $today = new DateTimeImmutable('today');
    $calendarFromDate = $today->modify('-365 days')->format('Y-m-d');
    $calendarToDate = $today->modify('+730 days')->format('Y-m-d');
}

$leaveTypes = bp_fetch_leave_types();
$resolvedStaffName = (string)($staff['staff_name'] ?? '');
$balances = bp_fetch_leave_balances(
    $resolvedStaffName,
    (string)($staff['unique_id'] ?? ''),
    $employeeId
);

// Temporary diagnostic: call with ?debug_balance=1 (or post debug_balance=1)
// to see exactly how the balance lookup is resolving for this user. Returns
// the resolved name + raw rows the view returns for both an exact and a
// case-insensitive match, so we can pinpoint why balances are empty.
if (bp_str($input, 'debug_balance') === '1' || (($_GET['debug_balance'] ?? '') === '1')) {
    $exactRows = bp_fetch_rows(
        'vw_leave_balance',
        ['staff_name', 'leave_type', 'leave_master_id', 'used_leave', 'balance'],
        ['staff_name' => $resolvedStaffName]
    );
    $likeRows = bp_fetch_rows(
        'vw_leave_balance',
        ['staff_name', 'leave_type', 'leave_master_id', 'used_leave', 'balance'],
        'UPPER(TRIM(staff_name)) LIKE UPPER(TRIM(' . bp_sql_quote('%' . $resolvedStaffName . '%') . '))'
    );
    $sampleNames = bp_fetch_rows('vw_leave_balance', ['DISTINCT staff_name'], '1=1');
    bp_send_json([
        'status' => true,
        'message' => 'Balance debug',
        'debug' => [
            'input_staff_id' => $staffId,
            'resolved_employee_id' => $employeeId,
            'resolved_staff_name' => $resolvedStaffName,
            'staff_row_unique_id' => (string)($staff['unique_id'] ?? ''),
            'balances_returned_count' => count($balances),
            'exact_match_count' => count($exactRows),
            'like_match_count' => count($likeRows),
            'like_match_rows' => $likeRows,
            'view_distinct_name_sample' => array_slice(array_map(
                static fn($r) => (string)($r['staff_name'] ?? ''),
                $sampleNames
            ), 0, 25),
        ],
    ]);
}
$attendanceMonthFrom = bp_date_ymd(bp_str($input, 'attendance_month_from'));
$attendanceMonthTo = bp_date_ymd(bp_str($input, 'attendance_month_to'));
$attendanceSummary = bp_fetch_attendance_summary(
    $employeeId,
    $attendanceMonthFrom,
    $attendanceMonthTo,
    (string)($staff['unique_id'] ?? '')
);
$permissionBalance = bp_leave_balance_for_keyword($balances, 'permission');
if ($permissionBalance !== null) {
    $attendanceSummary['permissions_left'] = $permissionBalance;
} else {
    $attendanceSummary['permissions_left'] = 0.0;
}
$flexiHolidays = bp_fetch_flexi_holidays(50);
$weekoffDates = bp_fetch_weekoff_dates_for_staff($staff, $calendarFromDate, $calendarToDate);

$myEntries = bp_fetch_leave_entries_by_employee($employeeId);
$myEntries = bp_attach_leave_meta(array_slice($myEntries, 0, 50));

$pendingMine = 0;
foreach ($myEntries as $entry) {
    if ((int)$entry['status'] === 0) {
        $pendingMine++;
    }
}

$isOfficer = bp_is_reporting_officer($employeeId);
$isHrUser = bp_is_hr_staff($employeeId);
$approvalEntries = [];
$pendingApprovals = 0;

if ($isHrUser) {
    $approvalEntries = bp_fetch_leave_entries_for_hr(null, null, 0);
    $pendingApprovals = count($approvalEntries);
    $approvalEntries = bp_attach_leave_meta(array_slice($approvalEntries, 0, 50));
} elseif ($isOfficer) {
    $approvalEntries = bp_fetch_leave_entries_for_officer($employeeId, null, null, 0);
    $pendingApprovals = count($approvalEntries);
    $approvalEntries = bp_attach_leave_meta(array_slice($approvalEntries, 0, 50));
}

$unreadNotifications = bp_notifications_unread_count($employeeId);
$recentNotifications = bp_fetch_notifications($employeeId, true, 10);

bp_send_json([
    'status' => true,
    'message' => 'Leave dashboard loaded',
    'data' => [
        'employee' => [
            'staff_unique_id' => $employeeId,
            'staff_row_unique_id' => (string)($staff['unique_id'] ?? ''),
            'employee_id' => $employeeId,
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'reporting_officer' => (string)($staff['reporting_officer'] ?? ''),
            'office_email_id' => (string)($staff['office_email_id'] ?? ''),
        ],
        'is_reporting_officer' => $isOfficer,
        'is_hr_user' => $isHrUser,
        'leave_types' => $leaveTypes,
        'flexi_holidays' => $flexiHolidays,
        'attendance_summary' => $attendanceSummary,
        'weekoff_dates' => $weekoffDates,
        'weekoff_calendar_from' => $calendarFromDate,
        'weekoff_calendar_to' => $calendarToDate,
        'leave_balances' => $balances,
        'my_pending_count' => $pendingMine,
        'pending_requests_count' => $pendingMine,
        'pending_approvals_count' => $pendingApprovals,
        'unread_notifications_count' => $unreadNotifications,
        'recent_notifications' => $recentNotifications,
        'my_recent_leaves' => $myEntries,
        'approval_pending_leaves' => $approvalEntries,
        'server_time' => bp_now(),
    ],
]);
