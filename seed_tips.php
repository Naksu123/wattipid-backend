<?php
/**
 * Wattipid Tips Database Seeder
 * 
 * Populates the electricity_tips table with 60+ curated, dorm-specific
 * electricity-saving tips across all 12 categories.
 * 
 * Run once: c:\xampp\php\php.exe seed_tips.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

$tips = [
    // ======================== AIR CONDITIONING ========================
    ['title' => 'Set AC to 25°C', 'message' => 'Setting your air conditioner to 25°C instead of lower temperatures can reduce energy consumption by up to 20%. This temperature is comfortable for sleeping and studying while keeping your electricity bill manageable.', 'category' => 'Air Conditioning', 'icon' => 'snow-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Use AC Timer Mode', 'message' => 'Set your air conditioner timer to turn off after 2-3 hours. Your room will stay cool long enough for you to fall asleep, and you avoid running the AC all night which can consume 6-8 kWh.', 'category' => 'Air Conditioning', 'icon' => 'timer-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Close Windows When AC is On', 'message' => 'Always keep doors and windows shut when the air conditioner is running. Open gaps force the compressor to work harder to maintain temperature, wasting significant energy and increasing your bill.', 'category' => 'Air Conditioning', 'icon' => 'lock-closed-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Clean AC Filters Monthly', 'message' => 'Dust buildup on AC filters restricts airflow, forcing the unit to consume more power. Cleaning or replacing filters every month can improve efficiency by 5-15% and extend the life of the unit.', 'category' => 'Air Conditioning', 'icon' => 'construct-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Use Fan Mode First', 'message' => 'Before switching to cooling mode, try running your AC on fan mode for the first 10 minutes. This circulates existing cool air and reduces the time the compressor needs to run at full power.', 'category' => 'Air Conditioning', 'icon' => 'leaf-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],

    // ======================== FAN USAGE ========================
    ['title' => 'Use Fans Instead of AC', 'message' => 'A ceiling or standing fan uses only 50-75 watts compared to an air conditioner that uses 900-1500 watts. On mild days, using a fan alone can cut your cooling costs by over 90%.', 'category' => 'Fan Usage', 'icon' => 'leaf-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Set Fan to Medium Speed', 'message' => 'Running your fan on medium speed instead of high can save energy while still providing adequate airflow. The difference in comfort is minimal but the energy savings add up over a full month.', 'category' => 'Fan Usage', 'icon' => 'speedometer-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],
    ['title' => 'Turn Off Fans When Leaving', 'message' => 'Fans cool people, not rooms. Unlike AC, a fan does not lower the room temperature. Always turn off fans when you leave the room since they provide no benefit when no one is present.', 'category' => 'Fan Usage', 'icon' => 'exit-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Use Oscillation Mode', 'message' => 'Enable the oscillation feature on your standing fan to distribute air evenly across the room. This allows you to use a lower speed setting while still feeling comfortable airflow.', 'category' => 'Fan Usage', 'icon' => 'sync-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],
    ['title' => 'Combine Fan with AC', 'message' => 'Using a fan together with your AC allows you to set the thermostat 2-3 degrees higher while maintaining the same comfort level. The fan circulates cool air efficiently, reducing AC workload significantly.', 'category' => 'Fan Usage', 'icon' => 'git-merge-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],

    // ======================== CHARGING DEVICES ========================
    ['title' => 'Unplug Chargers When Full', 'message' => 'Phone and laptop chargers continue drawing phantom power even after your device reaches 100%. Unplugging chargers when fully charged prevents unnecessary standby energy consumption throughout the day.', 'category' => 'Charging Devices', 'icon' => 'battery-full-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],
    ['title' => 'Charge During Off-Peak Hours', 'message' => 'Charging your devices during off-peak hours (late night or early morning) can help reduce strain on the dormitory electrical system and may result in more stable voltage delivery to your devices.', 'category' => 'Charging Devices', 'icon' => 'moon-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],
    ['title' => 'Avoid Overnight Charging', 'message' => 'Leaving your phone plugged in all night wastes energy for 5-6 hours after it reaches full charge. Modern phones charge fully in 1-2 hours, so plug in before bed and unplug before sleeping.', 'category' => 'Charging Devices', 'icon' => 'alert-circle-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],
    ['title' => 'Use Original Chargers', 'message' => 'Non-certified chargers can be less energy efficient and may draw more power than necessary. Using the original manufacturer charger ensures optimal charging speed and minimizes wasted energy.', 'category' => 'Charging Devices', 'icon' => 'checkmark-circle-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],
    ['title' => 'Use a Smart Power Strip', 'message' => 'A smart power strip automatically cuts power to devices when they are fully charged or in standby mode. This eliminates phantom loads from multiple chargers and adapters plugged into a single outlet.', 'category' => 'Charging Devices', 'icon' => 'flash-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],

    // ======================== KITCHEN APPLIANCES ========================
    ['title' => 'Use Electric Kettle Efficiently', 'message' => 'Only boil the amount of water you actually need. Filling a full kettle when you only need one cup wastes energy heating unnecessary water. An electric kettle is still more efficient than a stove.', 'category' => 'Kitchen Appliances', 'icon' => 'cafe-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],
    ['title' => 'Use Rice Cooker Timer', 'message' => 'If your rice cooker has a keep-warm function, avoid leaving it on for hours after cooking. The keep-warm mode can consume 30-40 watts continuously. Transfer rice to a container and unplug the cooker.', 'category' => 'Kitchen Appliances', 'icon' => 'restaurant-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],
    ['title' => 'Microwave Over Stove', 'message' => 'A microwave uses significantly less energy than an electric stove for reheating food. It heats food directly rather than heating a burner first, making it up to 80% more energy efficient for small portions.', 'category' => 'Kitchen Appliances', 'icon' => 'radio-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Defrost Food Naturally', 'message' => 'Instead of using your microwave to defrost frozen food, plan ahead and move it to the refrigerator the night before. This saves the microwave energy and helps keep your fridge cool at the same time.', 'category' => 'Kitchen Appliances', 'icon' => 'time-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],
    ['title' => 'Match Pot Size to Burner', 'message' => 'Using a small pot on a large electric burner wastes up to 40% of the heat energy. Always match your cookware size to the burner to ensure maximum heat transfer and minimum energy waste.', 'category' => 'Kitchen Appliances', 'icon' => 'resize-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],

    // ======================== REFRIGERATOR USAGE ========================
    ['title' => 'Keep Fridge 70% Full', 'message' => 'A refrigerator works most efficiently when it is about 70% full. The thermal mass of the stored food helps maintain temperature, reducing how often the compressor needs to cycle on.', 'category' => 'Refrigerator Usage', 'icon' => 'cube-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Don\'t Put Hot Food Inside', 'message' => 'Placing hot or warm food directly into the refrigerator forces the compressor to work overtime to bring the internal temperature back down. Always let food cool to room temperature first.', 'category' => 'Refrigerator Usage', 'icon' => 'thermometer-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Check Door Seals', 'message' => 'A loose or damaged refrigerator door seal allows cold air to leak out, forcing the compressor to run more frequently. Test your seal by closing the door on a piece of paper — if it slides out easily, the seal needs replacement.', 'category' => 'Refrigerator Usage', 'icon' => 'shield-checkmark-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Set Correct Temperature', 'message' => 'Set your refrigerator to 3-5°C and your freezer to -18°C. Each degree colder than necessary increases energy consumption by approximately 5%. Use a thermometer to verify accuracy.', 'category' => 'Refrigerator Usage', 'icon' => 'options-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Keep Fridge Away from Heat', 'message' => 'Position your refrigerator away from direct sunlight, stoves, and other heat sources. External heat forces the compressor to work harder, increasing energy consumption by up to 15%.', 'category' => 'Refrigerator Usage', 'icon' => 'sunny-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],

    // ======================== LAUNDRY ========================
    ['title' => 'Wash with Cold Water', 'message' => 'About 90% of the energy used by a washing machine goes to heating water. Washing clothes in cold water is just as effective for regular loads and dramatically reduces electricity consumption per wash cycle.', 'category' => 'Laundry', 'icon' => 'water-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Wash Full Loads Only', 'message' => 'Running a half-empty washing machine wastes the same amount of energy as a full load. Wait until you have enough clothes for a complete load before starting the machine to maximize efficiency.', 'category' => 'Laundry', 'icon' => 'basket-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Use High Spin Speed', 'message' => 'Selecting a higher spin speed during the wash cycle removes more water from your clothes. This means less time needed for drying, whether you use a dryer or hang clothes to air dry.', 'category' => 'Laundry', 'icon' => 'sync-circle-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],
    ['title' => 'Air Dry When Possible', 'message' => 'Electric dryers consume 2,000-5,000 watts per cycle. Hanging clothes on a drying rack or clothesline costs zero electricity and is gentler on fabrics, extending the life of your clothing.', 'category' => 'Laundry', 'icon' => 'partly-sunny-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Clean Lint Filters', 'message' => 'If you use a dryer, clean the lint filter before every load. A clogged filter restricts airflow, forcing the dryer to run longer and consume more energy to dry the same amount of clothes.', 'category' => 'Laundry', 'icon' => 'funnel-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],

    // ======================== STUDY SETUP ========================
    ['title' => 'Use Laptop Over Desktop', 'message' => 'Laptops consume 30-70 watts compared to 200-500 watts for desktop computers with monitors. For studying and basic tasks, a laptop is significantly more energy efficient and portable.', 'category' => 'Study Setup', 'icon' => 'laptop-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Lower Screen Brightness', 'message' => 'Reducing your laptop or monitor brightness from 100% to 60-70% can decrease display energy consumption by up to 30%. Your eyes will also experience less strain during long study sessions.', 'category' => 'Study Setup', 'icon' => 'contrast-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Enable Power Saver Mode', 'message' => 'Activate your laptop or computer power saver mode when studying. This reduces CPU performance slightly (unnoticeable for documents and browsing) while significantly reducing energy draw.', 'category' => 'Study Setup', 'icon' => 'battery-half-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Use Task Lighting', 'message' => 'Instead of lighting the entire room, use a small LED desk lamp focused on your study area. A 5-watt LED desk lamp provides sufficient light for reading while using a fraction of the energy of overhead lights.', 'category' => 'Study Setup', 'icon' => 'flashlight-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Turn Off Monitor During Breaks', 'message' => 'If you take a study break longer than 10 minutes, turn off your monitor. Screen savers do NOT save energy — only turning off the display actually reduces consumption.', 'category' => 'Study Setup', 'icon' => 'desktop-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],

    // ======================== SHARED ROOM EFFICIENCY ========================
    ['title' => 'Coordinate AC Usage', 'message' => 'If you share a room, coordinate with your roommate on AC usage schedules. Agreeing on a comfortable temperature and operating hours prevents one person from running the AC all day.', 'category' => 'Shared Room Efficiency', 'icon' => 'people-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Share Common Appliances', 'message' => 'Instead of each roommate having separate rice cookers, kettles, or mini-fridges, share one unit. This reduces the total number of appliances drawing power in your room.', 'category' => 'Shared Room Efficiency', 'icon' => 'git-network-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Assign an Energy Champion', 'message' => 'Designate one roommate as the weekly energy monitor. Their job is to ensure all lights, fans, and appliances are off before everyone leaves the room, creating accountability.', 'category' => 'Shared Room Efficiency', 'icon' => 'ribbon-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Use Shared Study Hours', 'message' => 'Study together during the same hours to share the lighting and AC. If both roommates need the lights on at different times, the room uses power for twice as long.', 'category' => 'Shared Room Efficiency', 'icon' => 'book-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Set Room Energy Goals', 'message' => 'Use the Wattipid daily consumption limit feature to set a shared room energy goal. Track your progress together on the dashboard and celebrate when you stay under budget.', 'category' => 'Shared Room Efficiency', 'icon' => 'trophy-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],

    // ======================== GAMING & ENTERTAINMENT ========================
    ['title' => 'Lower Game Graphics Settings', 'message' => 'High graphics settings force your GPU to draw maximum power. Reducing resolution or detail levels can cut your gaming system power consumption by 30-50% while still providing an enjoyable experience.', 'category' => 'Gaming & Entertainment', 'icon' => 'game-controller-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Turn Off Gaming PC When Done', 'message' => 'Gaming PCs consume 200-500 watts during use and 10-30 watts on standby. Always fully shut down (not sleep mode) your gaming computer when you are done to eliminate standby power draw.', 'category' => 'Gaming & Entertainment', 'icon' => 'power-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Use Headphones Over Speakers', 'message' => 'Headphones consume negligible energy compared to powered speakers or soundbars. For late-night gaming or entertainment, headphones save electricity while also being courteous to your roommates.', 'category' => 'Gaming & Entertainment', 'icon' => 'headset-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],
    ['title' => 'Stream on Smaller Screens', 'message' => 'Watching Netflix or YouTube on your phone or tablet uses significantly less power than streaming on a large TV or monitor. A 10-inch tablet uses roughly 5 watts compared to 80+ watts for a 32-inch TV.', 'category' => 'Gaming & Entertainment', 'icon' => 'phone-portrait-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Set Auto-Sleep on Consoles', 'message' => 'Configure your gaming console (PS5, Xbox, Switch) to automatically enter rest mode after 30 minutes of inactivity. This prevents the console from running at full power when you fall asleep or forget.', 'category' => 'Gaming & Entertainment', 'icon' => 'alarm-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],

    // ======================== APPLIANCE MAINTENANCE ========================
    ['title' => 'Clean Appliance Vents', 'message' => 'Dust accumulation on the vents and coils of appliances like refrigerators and AC units forces them to work harder. Regular cleaning every 2-3 months maintains peak efficiency and prevents overheating.', 'category' => 'Appliance Maintenance', 'icon' => 'build-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Replace Old Extension Cords', 'message' => 'Worn or low-quality extension cords can cause energy loss through resistance heating. Replace frayed or warm-to-the-touch extension cords with new, properly rated ones to ensure safe and efficient power delivery.', 'category' => 'Appliance Maintenance', 'icon' => 'swap-horizontal-outline', 'difficulty' => 'Medium', 'savings_level' => 'Low'],
    ['title' => 'Inspect Wiring Connections', 'message' => 'Loose electrical connections generate heat and waste energy. Periodically check that all plugs fit snugly in outlets and report any sparking, buzzing, or warm outlets to your landlord immediately.', 'category' => 'Appliance Maintenance', 'icon' => 'warning-outline', 'difficulty' => 'Medium', 'savings_level' => 'Low'],
    ['title' => 'Defrost Freezer Regularly', 'message' => 'Ice buildup thicker than 5mm inside a freezer acts as insulation, forcing the compressor to work harder. Defrost your freezer when ice buildup becomes noticeable to maintain optimal energy efficiency.', 'category' => 'Appliance Maintenance', 'icon' => 'snow-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Service AC Annually', 'message' => 'An annual professional servicing of your air conditioner ensures refrigerant levels are correct, coils are clean, and the compressor is functioning optimally. A well-maintained AC uses 15-20% less energy.', 'category' => 'Appliance Maintenance', 'icon' => 'hammer-outline', 'difficulty' => 'Hard', 'savings_level' => 'High'],

    // ======================== DAILY HABITS ========================
    ['title' => 'Switch to LED Bulbs', 'message' => 'LED bulbs use up to 80% less electricity than traditional incandescent bulbs and last 25 times longer. Replacing just 5 incandescent bulbs with LEDs can save over 500 kWh per year.', 'category' => 'Daily Habits', 'icon' => 'bulb-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Turn Off Lights When Leaving', 'message' => 'Make it a habit to always switch off lights when you leave a room. Even energy-efficient LED lights waste electricity when illuminating an empty space. A simple habit that saves significantly over time.', 'category' => 'Daily Habits', 'icon' => 'log-out-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Use Natural Light', 'message' => 'Open curtains and blinds during the day to take advantage of natural sunlight. Position your study desk near a window to reduce the need for artificial lighting during daytime hours.', 'category' => 'Daily Habits', 'icon' => 'sunny-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Unplug Idle Electronics', 'message' => 'TVs, chargers, and game consoles on standby collectively account for up to 10% of household energy use. Unplug devices you are not actively using, especially before leaving your room for class.', 'category' => 'Daily Habits', 'icon' => 'flash-off-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Monitor Your Consumption Daily', 'message' => 'Check your Wattipid dashboard daily to understand your energy patterns. Awareness is the first step to conservation. Set daily consumption limits and track whether you stay within your budget.', 'category' => 'Daily Habits', 'icon' => 'analytics-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Take Shorter Showers', 'message' => 'If your dormitory uses an electric water heater, shorter showers directly reduce electricity consumption. Reducing shower time from 15 to 5 minutes can save up to 3 kWh per day.', 'category' => 'Daily Habits', 'icon' => 'rainy-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
];

echo "Wattipid Tips Database Seeder\n";
echo "============================\n\n";

// Check existing tips
$existing = $conn->query("SELECT COUNT(*) as cnt FROM electricity_tips")->fetch(PDO::FETCH_ASSOC);
echo "Existing tips in database: {$existing['cnt']}\n\n";

$inserted = 0;
$skipped = 0;

$stmt = $conn->prepare("
    INSERT INTO electricity_tips (title, message, category, icon, difficulty, savings_level, dorm_relevance, is_active) 
    VALUES (?, ?, ?, ?, ?, ?, 'Student', 1)
");

// Check for duplicates by title before inserting
$checkStmt = $conn->prepare("SELECT id FROM electricity_tips WHERE title = ?");

foreach ($tips as $tip) {
    $checkStmt->execute([$tip['title']]);
    if ($checkStmt->fetch()) {
        $skipped++;
        echo "  SKIP: '{$tip['title']}' (already exists)\n";
        continue;
    }

    $stmt->execute([
        $tip['title'],
        $tip['message'],
        $tip['category'],
        $tip['icon'],
        $tip['difficulty'],
        $tip['savings_level']
    ]);
    $inserted++;
    echo "  ADD:  '{$tip['title']}' [{$tip['category']}]\n";
}

echo "\n============================\n";
echo "Inserted: {$inserted}\n";
echo "Skipped (duplicates): {$skipped}\n";

// Final count
$final = $conn->query("SELECT COUNT(*) as cnt FROM electricity_tips")->fetch(PDO::FETCH_ASSOC);
echo "Total tips now: {$final['cnt']}\n\n";

// Category breakdown
echo "Category breakdown:\n";
$cats = $conn->query("SELECT category, COUNT(*) as cnt FROM electricity_tips GROUP BY category ORDER BY category")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cats as $cat) {
    echo "  {$cat['category']}: {$cat['cnt']} tips\n";
}
echo "\nDone!\n";
