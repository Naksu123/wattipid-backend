<?php
/**
 * Database Seeder for Electricity Tips
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$tips = [
    // Energy Saving (15)
    ["title" => "Unplug Idle Chargers", "message" => "Unplug chargers immediately after your phone or laptop reaches 100% to stop phantom power draw.", "category" => "Energy Saving", "icon" => "battery-dead-outline"],
    ["title" => "Optimized Fan Speed", "message" => "Use your electric fan on the lowest comfortable setting since higher speeds consume significantly more wattage.", "category" => "Energy Saving", "icon" => "leaf-outline"],
    ["title" => "Daytime Study Habit", "message" => "Study near windows during the daytime to utilize natural sunlight instead of turning on room lights.", "category" => "Energy Saving", "icon" => "sunny-outline"],
    ["title" => "Screen Brightness", "message" => "Lower your laptop screen brightness to 50% or less to extend battery life and reduce charging frequency.", "category" => "Energy Saving", "icon" => "tablet-portrait-outline"],
    ["title" => "Exit Checklist", "message" => "Turn off all lights and fans whenever you leave your dorm room, even if it is just for a few minutes.", "category" => "Energy Saving", "icon" => "exit-outline"],
    ["title" => "Strategic Airflow", "message" => "Position your electric fan to circulate air from a window rather than just pushing hot air around the room.", "category" => "Energy Saving", "icon" => "shuffle-outline"],
    ["title" => "Device Power Saving", "message" => "Enable 'Power Saving Mode' on all your mobile devices to reduce the background energy consumption of apps.", "category" => "Energy Saving", "icon" => "battery-charging-outline"],
    ["title" => "Offline Study Mode", "message" => "Switch off your laptop's Wi-Fi and Bluetooth when studying offline to save battery and electricity.", "category" => "Energy Saving", "icon" => "wifi-outline"],
    ["title" => "Avoid Daisy-Chaining", "message" => "Avoid using multiple extension cords plugged into each other as this creates energy loss through heat.", "category" => "Energy Saving", "icon" => "alert-circle-outline"],
    ["title" => "Bulk Ironing", "message" => "Iron your school uniforms in one weekly batch instead of heating up the iron every single morning.", "category" => "Energy Saving", "icon" => "shirt-outline"],
    ["title" => "Hibernate Over Sleep", "message" => "Set your laptop to 'Hibernate' instead of 'Sleep' mode during long study breaks to cut power usage to zero.", "category" => "Energy Saving", "icon" => "moon-outline"],
    ["title" => "Rice Cooker Timing", "message" => "Ensure your rice cooker is unplugged as soon as the rice is cooked to avoid the energy-heavy 'Keep Warm' mode.", "category" => "Energy Saving", "icon" => "restaurant-outline"],
    ["title" => "Heat Blockage", "message" => "Close your curtains during the hottest part of the afternoon to keep the room cool and reduce fan usage.", "category" => "Energy Saving", "icon" => "browsers-outline"],
    ["title" => "Clean Fan Blades", "message" => "Clean your electric fan blades once a month to prevent dust buildup from making the motor work harder.", "category" => "Energy Saving", "icon" => "construct-outline"],
    ["title" => "Focused Lighting", "message" => "Use a desk lamp for focused studying at night instead of lighting up the entire dormitory room.", "category" => "Energy Saving", "icon" => "bulb-outline"],

    // Appliance Safety (8)
    ["title" => "Kettle Safety", "message" => "Never leave an electric kettle plugged in after the water has finished boiling to prevent overheating.", "category" => "Appliance Safety", "icon" => "water-outline"],
    ["title" => "Dry Hands Only", "message" => "Ensure your hands are completely dry before plugging or unplugging any dorm appliance to avoid electric shocks.", "category" => "Appliance Safety", "icon" => "hand-right-outline"],
    ["title" => "Cord Inspection", "message" => "Check extension cords for any frayed wires or exposed copper and replace them immediately for fire safety.", "category" => "Appliance Safety", "icon" => "warning-outline"],
    ["title" => "Safe Charging Surface", "message" => "Do not place your laptop on a bed or pillow while charging to prevent the vents from being blocked.", "category" => "Appliance Safety", "icon" => "bed-outline"],
    ["title" => "Load Balancing", "message" => "Avoid plugging high-wattage appliances like rice cookers and kettles into the same thin extension cord.", "category" => "Appliance Safety", "icon" => "flash-outline"],
    ["title" => "Water Distance", "message" => "Keep all electrical cords away from water sources in the dorm, especially near the bathroom or sink area.", "category" => "Appliance Safety", "icon" => "alert-circle-outline"],
    ["title" => "Motor Heat Check", "message" => "Unplug your electric fan if you notice a burning smell or if the motor starts making unusual grinding noises.", "category" => "Appliance Safety", "icon" => "nuclear-outline"],
    ["title" => "Fridge Clearance", "message" => "Ensure there is at least 6 inches of space behind your mini-fridge for proper ventilation and heat dispersal.", "category" => "Appliance Safety", "icon" => "snow-outline"],

    // Budget Friendly (8)
    ["title" => "Shared Fan Cooling", "message" => "Coordinate with roommates to share one electric fan during group study sessions to split the electricity cost.", "category" => "Budget Friendly", "icon" => "people-outline"],
    ["title" => "Thermal Flask Hack", "message" => "Use a thermal flask to store hot water from your kettle so you don't have to boil it multiple times a day.", "category" => "Budget Friendly", "icon" => "cafe-outline"],
    ["title" => "Peak Hour Tracking", "message" => "Review your daily Wattipid analytics to identify which hours your room consumes the most expensive electricity.", "category" => "Budget Friendly", "icon" => "stats-chart-outline"],
    ["title" => "Avoid Peak Grid", "message" => "Avoid using high-wattage appliances during peak evening hours when the local power grid is most stressed.", "category" => "Budget Friendly", "icon" => "time-outline"],
    ["title" => "Large Batch Cooking", "message" => "Cook meals in larger batches using your rice cooker to minimize the number of times you use the appliance.", "category" => "Budget Friendly", "icon" => "fast-food-outline"],
    ["title" => "Short Heater Use", "message" => "Limit the use of electric water heaters for showers to less than five minutes to significantly lower your bill.", "category" => "Budget Friendly", "icon" => "timer-outline"],
    ["title" => "Dorm Budget Agreement", "message" => "Discuss an electricity budget with your roommates to ensure everyone is conscious of their appliance usage.", "category" => "Budget Friendly", "icon" => "wallet-outline"],
    ["title" => "App Dark Mode", "message" => "Use dark mode on all your screens to slightly reduce the power consumption of OLED and high-brightness displays.", "category" => "Budget Friendly", "icon" => "contrast-outline"],

    // Smart Dorm Living (9)
    ["title" => "Off-Peak Charging", "message" => "Schedule your heavy laptop charging during off-peak hours to help balance the dormitory's overall power load.", "category" => "Smart Dorm Living", "icon" => "calendar-outline"],
    ["title" => "Individual Switches", "message" => "Use a power strip with individual switches so you can turn off specific chargers without unplugging everything.", "category" => "Smart Dorm Living", "icon" => "toggle-outline"],
    ["title" => "Airflow Clearance", "message" => "Keep your dorm room organized so that airflow from fans is not blocked by piles of laundry or boxes.", "category" => "Smart Dorm Living", "icon" => "cube-outline"],
    ["title" => "Shared Fridge Care", "message" => "Coordinate with dorm-mates to use shared appliances like fridges efficiently by not opening the door too often.", "category" => "Smart Dorm Living", "icon" => "snow-outline"],
    ["title" => "Last-Out Duty", "message" => "Create a 'last person out' checklist to ensure all lights and non-essential appliances are switched off.", "category" => "Smart Dorm Living", "icon" => "checkmark-circle-outline"],
    ["title" => "Rechargeable Lamp", "message" => "Use a rechargeable desk lamp that you can charge during the day and use at night for cord-free studying.", "category" => "Smart Dorm Living", "icon" => "bulb-outline"],
    ["title" => "Fridge Defrosting", "message" => "Defrost your mini-fridge regularly as ice buildup forces the compressor to run longer and use more power.", "category" => "Smart Dorm Living", "icon" => "ice-cream-outline"],
    ["title" => "Natural Drying", "message" => "Hang wet laundry near windows to dry naturally instead of using electric dryers or blowing fans directly on them.", "category" => "Smart Dorm Living", "icon" => "water-outline"],
    ["title" => "Study Area Bonus", "message" => "Utilize common study areas in your dormitory that are already lighted and ventilated to save on room electricity.", "category" => "Smart Dorm Living", "icon" => "library-outline"]
];

try {
    $conn->exec("TRUNCATE TABLE electricity_tips");
    $query = "INSERT INTO electricity_tips (title, message, category, icon) VALUES (:title, :message, :category, :icon)";
    $stmt = $conn->prepare($query);
    foreach ($tips as $tip) {
        $stmt->execute($tip);
    }
    echo json_encode(["success" => true, "message" => "Seeded " . count($tips) . " realistic tips successfully"]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Error seeding: " . $e->getMessage()]);
}
