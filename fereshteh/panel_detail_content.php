<?php
// This file is included by panels.php when a panel detail is requested
// $panel variable should be available from the including script

// Ensure the panel data is available
if (!isset($panel) || !is_array($panel)) {
    echo "خطا: اطلاعات پنل در دسترس نیست";
    exit;
}
?>

<div class="bg-gray-50 p-4 rounded-lg">
    <h3 class="text-lg font-bold mb-4">اطلاعات اصلی پنل</h3>
    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 mb-6">
        <div>
            <p class="text-sm text-gray-600">آدرس:</p>
            <p class="font-medium"><?php echo htmlspecialchars($panel['address']); ?></p>
        </div>
        <div>
            <p class="text-sm text-gray-600">نوع:</p>
            <p class="font-medium"><?php echo htmlspecialchars($panel['type'] ?? 'نامشخص'); ?></p>
        </div>
        <div>
            <p class="text-sm text-gray-600">مساحت:</p>
            <p class="font-medium"><?php echo htmlspecialchars($panel['area'] ?? 'نامشخص'); ?> مترمربع</p>
        </div>
        <div>
            <p class="text-sm text-gray-600">عرض:</p>
            <p class="font-medium"><?php echo htmlspecialchars($panel['width'] ?? 'نامشخص'); ?> متر</p>
        </div>
        <div>
            <p class="text-sm text-gray-600">طول:</p>
            <p class="font-medium"><?php echo htmlspecialchars($panel['length'] ?? 'نامشخص'); ?> متر</p>
        </div>
        <div>
            <p class="text-sm text-gray-600">نوع قالب:</p>
            <p class="font-medium"><?php echo htmlspecialchars($panel['formwork_type'] ?? 'نامشخص'); ?></p>
        </div>
    </div>

    <h3 class="text-lg font-bold mb-4">مراحل آماده‌سازی</h3>
    <div class="bg-white p-4 rounded-lg shadow-inner mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="custom-checkbox">
                <input type="checkbox" name="clean_status" class="panel-detail-checkbox" <?php echo ($panel['clean_status'] ?? 0) ? 'checked' : ''; ?>>
                <span>تمیزکاری قالب</span>
            </label>
            <label class="custom-checkbox">
                <input type="checkbox" name="oil_status" class="panel-detail-checkbox" <?php echo ($panel['oil_status'] ?? 0) ? 'checked' : ''; ?>>
                <span>روغن‌کاری قالب</span>
            </label>
            <label class="custom-checkbox">
                <input type="checkbox" name="mesh_status" class="panel-detail-checkbox" <?php echo ($panel['mesh_status'] ?? 0) ? 'checked' : ''; ?>>
                <span>شبکه‌بندی و آرماتوربندی</span>
            </label>
            <label class="custom-checkbox">
                <input type="checkbox" name="foam_status" class="panel-detail-checkbox" <?php echo ($panel['foam_status'] ?? 0) ? 'checked' : ''; ?>>
                <span>بستن فوم سر و ته</span>
            </label>
            <label class="custom-checkbox">
                <input type="checkbox" name="label_status" class="panel-detail-checkbox" <?php echo ($panel['label_status'] ?? 0) ? 'checked' : ''; ?>>
                <span>برچسب‌گذاری</span>
            </label>
        </div>
    </div>

    <h3 class="text-lg font-bold mb-4">زمان‌بندی فرآیند</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <?php
        $steps = [
            'formwork_start_time' => 'شروع قالب‌بندی',
            'formwork_end_time' => 'پایان قالب‌بندی',
            'assembly_start_time' => 'شروع مونتاژ',
            'assembly_end_time' => 'پایان مونتاژ',
            'mesh_start_time' => 'شروع شبکه‌بندی',
            'mesh_end_time' => 'پایان شبکه‌بندی',
            'concrete_start_time' => 'شروع بتن‌ریزی',
            'concrete_end_time' => 'پایان بتن‌ریزی'
        ];
        
        foreach($steps as $time_key => $label):
            $timestamp = $panel[$time_key] ?? null;
            $formatted_time = $timestamp ? date('Y/m/d H:i:s', strtotime($timestamp)) : '-';
        ?>
        <div>
            <p class="text-sm text-gray-600"><?php echo $label; ?></p>
            <p class="font-medium"><?php echo $formatted_time; ?></p>
            <button class="step-button start" onclick="updatePanelStep(<?php echo $panel['id']; ?>, '<?php echo $time_key; ?>', 'start')">شروع</button>
            <button class="step-button end" onclick="updatePanelStep(<?php echo $panel['id']; ?>, '<?php echo $time_key; ?>', 'end')">پایان</button>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function updatePanelStep(panelId, step, action) {
    const csrfToken = "<?php echo $_SESSION['csrf_token']; ?>";
    $.ajax({
        url: 'panel_detail.php',
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'updatePanelStep',
            panelId: panelId,
            step: step,
            type: action,
            csrf_token: csrfToken
        },
        success: function(response) {
            if (response.success) {
                toastr.success('Action completed successfully.');
                location.reload(); // Reload the page to reflect changes
            } else {
                toastr.error('Error updating step: ' + (response.error || 'Unknown error'));
            }
        },
        error: function(xhr, status, error) {
            toastr.error('AJAX error: ' + status + ', ' + error);
        }
    });
}
</script>