<?php
function generate_calendar($month, $year, $holidays = [], $approvedLeaves = [], $approvedOvertimes = []) {
    $first_day = mktime(0, 0, 0, $month, 1, $year);
    $days_in_month = date('t', $first_day);
    $start_day = date('w', $first_day); // 星期幾（0 = Sunday）
    $month_label = date('Y年m月', $first_day);

    echo "<div class='calendar-wrapper'>";
    echo "<table class='calendar-table'>";
    echo "<thead><tr>
            <th><div class='day-number'>日</div></th>
            <th><div class='day-number'>一</div></th>
            <th><div class='day-number'>二</div></th>
            <th><div class='day-number'>三</div></th>
            <th><div class='day-number'>四</div></th>
            <th><div class='day-number'>五</div></th>
            <th><div class='day-number'>六</div></th>
          </tr></thead><tbody><tr>";

    $day = 1;
    $cell = 0;

    // 開頭補空格
    for ($i = 0; $i < $start_day; $i++) {
        echo "<td></td>";
        $cell++;
    }

    while ($day <= $days_in_month) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $classes = [];
        $label = '';

        // 國定假日（不含補班日）
        if (isset($holidays[$date]) && $holidays[$date]['is_working_day'] == 0) {
            $classes[] = 'holiday';
            $label = $holidays[$date]['description'] ?? '國定假日';
        }
        // 周末（補班日除外）
        elseif ((date('w', strtotime($date)) == 0 || date('w', strtotime($date)) == 6) &&
                (!isset($holidays[$date]) || $holidays[$date]['is_working_day'] != 1)) {
            $classes[] = 'weekend';
            $label = '週末';
        }

        // 審核通過的請假
        if (isset($approvedLeaves[$date])) {
            $classes[] = 'leave-approved';
            $label = $approvedLeaves[$date]; // 顯示假別名稱
        }

        // 審核通過的加班
        if (isset($approvedOvertimes[$date])) {
            $classes[] = 'overtime-approved';
            $label = '加班';
        }

        $class_attr = $classes ? ' class="' . implode(' ', $classes) . '"' : '';

        echo "<td{$class_attr}>";
        echo "<span class='day-number'>{$day}</span>";
        if ($label) {
            echo "<span class='label'>" . htmlspecialchars($label) . "</span>";
        }
        echo "</td>";

        $day++;
        $cell++;

        if ($cell % 7 === 0 && $day <= $days_in_month) echo "</tr><tr>";
    }

    // 補結尾空格
    while ($cell % 7 !== 0) {
        echo "<td></td>";
        $cell++;
    }

    echo "</tr></tbody></table>";
    echo "<div class='month-label'>{$month_label}</div>";
    echo "</div>";
}
?>
