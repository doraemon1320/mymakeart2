<?php
session_start();
require_once "../db_connect.php";

// ✅ 權限驗證
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// ✅ 接收資料
$employee_id = $_POST['employee_id'] ?? null;
$year = $_POST['year'] ?? null;
$month = $_POST['month'] ?? null;
$status = 'Approved';

$base_salary = $_POST['base_salary'] ?? 0;
$base_salary_note = $_POST['base_salary_note'] ?? '';

$meal_allowance = $_POST['meal_allowance'] ?? 0;
$meal_allowance_note = $_POST['meal_allowance_note'] ?? '';

$attendance_bonus = $_POST['attendance_bonus'] ?? 0;
$attendance_bonus_note = $_POST['attendance_bonus_note'] ?? '';

$position_bonus = $_POST['position_bonus'] ?? 0;
$position_bonus_note = $_POST['position_bonus_note'] ?? '';

$skill_bonus = $_POST['skill_bonus'] ?? 0;
$skill_bonus_note = $_POST['skill_bonus_note'] ?? '';

$overtime_pay = $_POST['overtime_pay'] ?? 0;
$overtime_note = $_POST['overtime_note'] ?? 0;

$labor_insurance = $_POST['labor_insurance'] ?? 0;
$labor_insurance_note = $_POST['labor_insurance_note'] ?? '';

$health_insurance = $_POST['health_insurance'] ?? 0;
$health_insurance_note = $_POST['health_insurance_note'] ?? 0;

$leave_deduction = $_POST['leave_deduction'] ?? 0;
$leave_deduction_note = $_POST['leave_deduction_note'] ?? 0;

$absent_deduction = $_POST['absent_deduction'] ?? 0;
$absent_deduction_note = $_POST['absent_deduction_note'] ?? 0;

$vacation_cash = $_POST['vacation_cash'] ?? 0;
$vacation_cash_note = $_POST['vacation_cash_note'] ?? 0;

$gross_salary = $_POST['gross_salary'] ?? 0;
$total_deductions = $_POST['total_deductions'] ?? 0;
$net_salary = $_POST['net_salary'] ?? 0;
$vacation_cash_days = $_POST['vacation_cash_days'] ?? 0;
$vacation_cash_hours = $_POST['vacation_cash_hours'] ?? 0;

// ✅ 寫入 annual_leave_records ➜ 特休轉現金
$vacation_days = $_POST['vacation_cash_days'] ?? 0;
if ($vacation_days > 0) {
    $check_cash = $conn->prepare("SELECT COUNT(*) as cnt FROM annual_leave_records WHERE employee_id = ? AND year = ? AND month = ? AND status = '轉現金'");
    if (!$check_cash) die("轉現金檢查失敗：" . $conn->error);
    $check_cash->bind_param("iii", $employee_id, $year, $month);
    $check_cash->execute();
    $res = $check_cash->get_result();
    $row = $res->fetch_assoc();
    if ((int)$row['cnt'] == 0) {
        $insert_cash = $conn->prepare("INSERT INTO annual_leave_records (employee_id, year, month, days, status, created_at) VALUES (?, ?, ?, ?, '轉現金', NOW())");
        if (!$insert_cash) die("寫入轉現金失敗：" . $conn->error);
        $insert_cash->bind_param("iiii", $employee_id, $year, $month, $vacation_days);
        if (!$insert_cash->execute()) {
            die("轉現金寫入失敗：" . $insert_cash->error);
        }
    }
}




// ✅ 判斷是否已存在
$check_sql = "SELECT id FROM employee_monthly_salary WHERE employee_id = ? AND year = ? AND month = ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("iii", $employee_id, $year, $month); // 修正為 "iii"
$check_stmt->execute();
$check_result = $check_stmt->get_result();
$exists = $check_result->num_rows > 0;

if ($exists) {
    // ✅ 更新
    $sql = "UPDATE employee_monthly_salary SET 
        base_salary = ?, base_salary_note = ?, meal_allowance = ?,meal_allowance_note = ?, attendance_bonus = ?, attendance_bonus_note = ?, 
        position_bonus = ?, position_bonus_note = ?, skill_bonus = ?, skill_bonus_note = ?, 
		overtime_pay = ?, overtime_note = ?,labor_insurance = ?, labor_insurance_note = ?, 
        health_insurance = ?,health_insurance_note = ?, leave_deduction = ?, leave_deduction_note = ?, absent_deduction = ?, absent_deduction_note = ?, vacation_cash = ?, vacation_cash_note = ?,
		gross_salary = ?, total_deductions = ?, net_salary = ?, 
        vacation_cash_days = ?, vacation_cash_hours = ?, status = ? 
        WHERE employee_id = ? AND year = ? AND month = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isisisisisisisisisissssssiisiii", 
        $base_salary,$base_salary_note,
		$meal_allowance, $meal_allowance_note,
		$attendance_bonus, $attendance_bonus_note,
        $position_bonus, $position_bonus_note, 
		$skill_bonus, $skill_bonus_note, 
		$overtime_pay, $overtime_note,
		$labor_insurance, $labor_insurance_note, 
        $health_insurance, $health_insurance_note, 
		$leave_deduction, $leave_deduction_note,
		$absent_deduction, $absent_deduction_note, 
        $vacation_cash, $vacation_cash_note, 
		$gross_salary, $total_deductions, $net_salary, 
        $vacation_cash_days, $vacation_cash_hours, $status,
        $employee_id, $year, $month
    );
} else {
    // ✅ 新增
    $sql = "INSERT INTO employee_monthly_salary (
        base_salary, meal_allowance, attendance_bonus, position_bonus, skill_bonus, overtime_pay, labor_insurance, 
        health_insurance, leave_deduction, absent_deduction, vacation_cash, 
        base_salary_note, meal_allowance_note, attendance_bonus_note, position_bonus_note, skill_bonus_note, overtime_note, 
        labor_insurance_note, health_insurance_note, leave_deduction_note, absent_deduction_note, vacation_cash_note, 
        gross_salary, total_deductions, net_salary, vacation_cash_days, vacation_cash_hours, status, employee_id, year, month
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiiiiiiiiissssssssssssdddisiii",
        $base_salary, $meal_allowance, $attendance_bonus, $position_bonus, $skill_bonus, $overtime_pay, $labor_insurance,
        $health_insurance, $leave_deduction, $absent_deduction, $vacation_cash,
        $base_salary_note, $meal_allowance_note, $attendance_bonus_note, $position_bonus_note, $skill_bonus_note, $overtime_note,
        $labor_insurance_note, $health_insurance_note, $leave_deduction_note, $absent_deduction_note, $vacation_cash_note,
        $gross_salary, $total_deductions, $net_salary, $vacation_cash_days, $vacation_cash_hours,
        $status, $employee_id, $year, $month
    );
}



if ($stmt->execute()) {
    echo "<script>
        alert('✅ 薪資資料已成功儲存！');
        window.location.href = 'employee_salary_report.php?employee_id=$employee_id&year=$year&month=$month';
    </script>";
} else {
    $error = addslashes($stmt->error); // 防止引號問題
    echo "<script>alert('❌ 儲存失敗：$error'); history.back();</script>";
}
?>
