<?php
/**
 * Wattipid General Electricity Consumption Tips Seeder
 * 12 Categories, 10 Tips each = 120 Total Tips
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

$tips = [
    // 1. General Energy Saving
    ['title' => 'Power Down When Unneeded', 'message' => 'Turn off electrical equipment when it is no longer needed to reduce overall electricity consumption.', 'category' => 'General Energy Saving', 'icon' => 'power-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Lights Out in Empty Rooms', 'message' => 'Avoid leaving lights on in unoccupied areas. Simply flicking the switch when you leave saves consistent energy.', 'category' => 'General Energy Saving', 'icon' => 'bulb-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Build Daily Habits', 'message' => 'Make energy-saving practices a natural part of your daily routine for long-term reduction in overall consumption.', 'category' => 'General Energy Saving', 'icon' => 'calendar-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Minimize Unnecessary Usage', 'message' => 'Reduce unnecessary electricity use whenever possible. Small reductions in operating times make a big difference.', 'category' => 'General Energy Saving', 'icon' => 'trending-down-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Embrace Natural Daylight', 'message' => 'Use natural daylight when practical. Open your curtains and enjoy the sun instead of using artificial lighting.', 'category' => 'General Energy Saving', 'icon' => 'sunny-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Don\'t Leave Devices Idle', 'message' => 'Avoid keeping electrical devices running when they are not actively being used.', 'category' => 'General Energy Saving', 'icon' => 'pause-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Review Your Habits', 'message' => 'Review your electricity habits regularly to identify new areas where you can reduce unnecessary power draw.', 'category' => 'General Energy Saving', 'icon' => 'search-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Encourage Housemates', 'message' => 'Encourage everyone in the household or room to practice energy conservation together.', 'category' => 'General Energy Saving', 'icon' => 'people-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Consistency is Key', 'message' => 'Make small energy-saving changes consistently rather than occasionally for the best overall impact.', 'category' => 'General Energy Saving', 'icon' => 'repeat-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Track Your Progress', 'message' => 'Monitor your overall consumption in Wattipid to see whether your daily habits are improving over time.', 'category' => 'General Energy Saving', 'icon' => 'analytics-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],

    // 2. Consumption Monitoring
    ['title' => 'Check Daily Usage', 'message' => 'Check your daily electricity consumption regularly to stay informed about your overall energy profile.', 'category' => 'Consumption Monitoring', 'icon' => 'calendar-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Compare with Yesterday', 'message' => 'Compare today\'s consumption with previous days to quickly spot any unusual increases in your usage.', 'category' => 'Consumption Monitoring', 'icon' => 'swap-horizontal-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Review Weekly Trends', 'message' => 'Review your weekly electricity consumption trend to understand your broader habits.', 'category' => 'Consumption Monitoring', 'icon' => 'stats-chart-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Track Monthly Consumption', 'message' => 'Monitor your monthly electricity consumption report to see the long-term impact of your conservation efforts.', 'category' => 'Consumption Monitoring', 'icon' => 'calendar-number-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Watch for Unusual Spikes', 'message' => 'Pay close attention to unusual increases in your overall consumption and try to recall any changes in your routine.', 'category' => 'Consumption Monitoring', 'icon' => 'alert-circle-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Record High Usage Periods', 'message' => 'Take note of the specific times when your electricity usage is noticeably higher.', 'category' => 'Consumption Monitoring', 'icon' => 'pencil-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Understand Your Habits', 'message' => 'Use Wattipid\'s consumption trends to build a better understanding of your overall electricity habits.', 'category' => 'Consumption Monitoring', 'icon' => 'book-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Measure Your Changes', 'message' => 'Compare different consumption periods to accurately measure if your new habits are making a difference.', 'category' => 'Consumption Monitoring', 'icon' => 'bar-chart-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Set Realistic Goals', 'message' => 'Set a realistic and achievable goal for reducing your overall electricity consumption.', 'category' => 'Consumption Monitoring', 'icon' => 'flag-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Stay Aware with Analytics', 'message' => 'Use Wattipid analytics to stay continuously aware of your electricity usage patterns.', 'category' => 'Consumption Monitoring', 'icon' => 'pie-chart-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],

    // 3. Daily Energy Habits
    ['title' => 'Lights Off When Leaving', 'message' => 'Always turn off the lights when leaving a room. It is a simple daily habit that yields constant savings.', 'category' => 'Daily Energy Habits', 'icon' => 'bulb-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Switch Off When Done', 'message' => 'Switch off equipment entirely when it is no longer needed to prevent ongoing power draw.', 'category' => 'Daily Energy Habits', 'icon' => 'power-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Reduce Daytime Usage', 'message' => 'Avoid unnecessary electricity use during the day when natural light and ventilation might suffice.', 'category' => 'Daily Energy Habits', 'icon' => 'sunny-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Utilize Natural Light', 'message' => 'Make full use of natural lighting whenever it is available to reduce reliance on indoor lighting.', 'category' => 'Daily Energy Habits', 'icon' => 'partly-sunny-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],
    ['title' => 'Final Room Check', 'message' => 'Check that unused electrical equipment is properly switched off before leaving the house.', 'category' => 'Daily Energy Habits', 'icon' => 'checkmark-done-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'No Purposeless Running', 'message' => 'Avoid keeping devices running without a clear purpose.', 'category' => 'Daily Energy Habits', 'icon' => 'stop-circle-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Build a Check Habit', 'message' => 'Develop a strong habit of checking electricity use before stepping out of a room.', 'category' => 'Daily Energy Habits', 'icon' => 'eye-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Use Only When Necessary', 'message' => 'Consume electricity only when absolutely necessary to optimize your overall daily footprint.', 'category' => 'Daily Energy Habits', 'icon' => 'leaf-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Involve Your Housemates', 'message' => 'Encourage everyone sharing the space to practice these daily energy-saving habits.', 'category' => 'Daily Energy Habits', 'icon' => 'people-circle-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Review Daily Patterns', 'message' => 'Review your daily consumption in Wattipid to better understand your typical usage pattern.', 'category' => 'Daily Energy Habits', 'icon' => 'calendar-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],

    // 4. Weekly Consumption
    ['title' => 'Compare Weeks', 'message' => 'Compare this week\'s overall consumption with the previous week to see if you are trending up or down.', 'category' => 'Weekly Consumption', 'icon' => 'swap-horizontal-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Spot Significant Changes', 'message' => 'Look for significant changes in your overall electricity usage from week to week.', 'category' => 'Weekly Consumption', 'icon' => 'analytics-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Identify High Usage Days', 'message' => 'Review which specific days of the week had higher overall consumption to identify patterns.', 'category' => 'Weekly Consumption', 'icon' => 'calendar-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Check Your Trajectory', 'message' => 'Check whether your weekly consumption is generally increasing or decreasing over time.', 'category' => 'Weekly Consumption', 'icon' => 'trending-up-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Set a Weekly Goal', 'message' => 'Set a realistic weekly electricity-saving goal to keep yourself motivated.', 'category' => 'Weekly Consumption', 'icon' => 'flag-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'End-of-Week Review', 'message' => 'Review your energy-saving habits at the end of each week to see what worked well.', 'category' => 'Weekly Consumption', 'icon' => 'checkbox-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Spot Repeated Highs', 'message' => 'Look for repeated periods of unusually high consumption across multiple weeks.', 'category' => 'Weekly Consumption', 'icon' => 'search-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Plan for Next Week', 'message' => 'Try consciously reducing unnecessary electricity use during the following week based on this week\'s data.', 'category' => 'Weekly Consumption', 'icon' => 'calendar-number-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Track Habit Impact', 'message' => 'Track whether your new energy-saving efforts actually produce a lower overall weekly trend.', 'category' => 'Weekly Consumption', 'icon' => 'bar-chart-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Improve Based on Data', 'message' => 'Use your weekly consumption data as a factual guide to improve your electricity habits.', 'category' => 'Weekly Consumption', 'icon' => 'bulb-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],

    // 5. Monthly Consumption
    ['title' => 'End-of-Month Review', 'message' => 'Review your total electricity consumption at the end of each month to see the big picture.', 'category' => 'Monthly Consumption', 'icon' => 'calendar-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Compare Months', 'message' => 'Compare your current month with previous months to identify seasonal or habit-based changes.', 'category' => 'Monthly Consumption', 'icon' => 'swap-vertical-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Identify Broad Trends', 'message' => 'Identify whether your monthly consumption is trending upward or downward.', 'category' => 'Monthly Consumption', 'icon' => 'trending-down-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Set a Monthly Target', 'message' => 'Set a realistic monthly consumption goal and use Wattipid to stay on track.', 'category' => 'Monthly Consumption', 'icon' => 'flag-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Adapt Based on Trends', 'message' => 'Review and adapt your energy-saving habits based on your monthly trend report.', 'category' => 'Monthly Consumption', 'icon' => 'sync-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Investigate Broad Changes', 'message' => 'Investigate unusual changes in overall consumption without assuming a specific cause or appliance.', 'category' => 'Monthly Consumption', 'icon' => 'search-outline', 'difficulty' => 'Hard', 'savings_level' => 'High'],
    ['title' => 'Plan Better Habits', 'message' => 'Use monthly analytics to plan better, long-term electricity-saving habits.', 'category' => 'Monthly Consumption', 'icon' => 'bulb-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Track Long-Term Progress', 'message' => 'Track your progress from month to month to ensure your efforts are sustainable.', 'category' => 'Monthly Consumption', 'icon' => 'stats-chart-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Monitor Reductions', 'message' => 'Monitor consistent reductions in overall electricity consumption and celebrate your progress.', 'category' => 'Monthly Consumption', 'icon' => 'checkmark-circle-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Use Reports as a Guide', 'message' => 'Use your monthly consumption report as a factual guide for improving future electricity usage.', 'category' => 'Monthly Consumption', 'icon' => 'document-text-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],

    // 6. High Consumption Awareness
    ['title' => 'Review High Usage Periods', 'message' => 'Review your usage when overall consumption is higher than usual to identify potential causes.', 'category' => 'High Consumption Awareness', 'icon' => 'alert-circle-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Check for Equipment Left On', 'message' => 'Check whether unnecessary electrical equipment was accidentally left running during high usage times.', 'category' => 'High Consumption Awareness', 'icon' => 'power-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Reflect on Recent Habits', 'message' => 'Review your recent electricity-use habits immediately after noticing an unusual increase.', 'category' => 'High Consumption Awareness', 'icon' => 'time-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Compare High Periods', 'message' => 'Compare the high-consumption period with previous, normal periods to gauge the difference.', 'category' => 'High Consumption Awareness', 'icon' => 'bar-chart-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Avoid Appliance Assumptions', 'message' => 'Do not assume that one specific appliance caused the increase; look at your overall habits.', 'category' => 'High Consumption Awareness', 'icon' => 'bulb-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Spot High Usage Patterns', 'message' => 'Look for repeated patterns of high overall consumption throughout the week.', 'category' => 'High Consumption Awareness', 'icon' => 'repeat-outline', 'difficulty' => 'Hard', 'savings_level' => 'High'],
    ['title' => 'Reduce Usage Next Time', 'message' => 'Make an effort to reduce unnecessary electricity usage during future high-consumption periods.', 'category' => 'High Consumption Awareness', 'icon' => 'trending-down-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Monitor Return to Normal', 'message' => 'Monitor the following days to determine whether your overall consumption successfully returns to normal.', 'category' => 'High Consumption Awareness', 'icon' => 'eye-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Set Awareness Goals', 'message' => 'Set a personal awareness goal to be more mindful when unusually high overall consumption occurs.', 'category' => 'High Consumption Awareness', 'icon' => 'flag-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Use Trends to Learn', 'message' => 'Use Wattipid trends to become more deeply aware of subtle changes in your electricity usage.', 'category' => 'High Consumption Awareness', 'icon' => 'analytics-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],

    // 7. Electricity Conservation
    ['title' => 'Turn Off When Unused', 'message' => 'Turn off unused lights and electrical equipment immediately when they are no longer providing value.', 'category' => 'Electricity Conservation', 'icon' => 'power-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Maximize Natural Light', 'message' => 'Make better use of available natural light during the day to minimize artificial lighting costs.', 'category' => 'Electricity Conservation', 'icon' => 'sunny-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Minimize Standby Use', 'message' => 'Avoid unnecessary standby usage where practical by unplugging equipment entirely.', 'category' => 'Electricity Conservation', 'icon' => 'flash-off-outline', 'difficulty' => 'Medium', 'savings_level' => 'Low'],
    ['title' => 'Use Only When Needed', 'message' => 'Make a conscious effort to use electricity only when it is actually needed.', 'category' => 'Electricity Conservation', 'icon' => 'leaf-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Be Consistent', 'message' => 'Practice energy-saving habits consistently to ensure long-term conservation success.', 'category' => 'Electricity Conservation', 'icon' => 'repeat-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Reduce Operating Time', 'message' => 'Reduce unnecessary operating time for electrical equipment to directly lower your total draw.', 'category' => 'Electricity Conservation', 'icon' => 'time-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Promote Conservation', 'message' => 'Encourage other occupants to conserve electricity alongside you.', 'category' => 'Electricity Conservation', 'icon' => 'people-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Don\'t Light Empty Spaces', 'message' => 'Avoid wasting electricity in unoccupied areas like empty rooms, bathrooms, or hallways.', 'category' => 'Electricity Conservation', 'icon' => 'bulb-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Review Habits Regularly', 'message' => 'Review your electricity habits regularly to find new ways to conserve power.', 'category' => 'Electricity Conservation', 'icon' => 'search-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Measure Conservation Efforts', 'message' => 'Monitor your overall consumption data to objectively measure your conservation efforts.', 'category' => 'Electricity Conservation', 'icon' => 'bar-chart-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],

    // 8. Energy Efficiency
    ['title' => 'Choose Efficient Replacements', 'message' => 'Consider energy-efficient products when replacing old or broken electrical equipment.', 'category' => 'Energy Efficiency', 'icon' => 'cart-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Look for Efficiency Labels', 'message' => 'Look for recognized energy-efficiency labels when purchasing new equipment.', 'category' => 'Energy Efficiency', 'icon' => 'pricetag-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Follow Maintenance Guidelines', 'message' => 'Maintain all electrical equipment according to manufacturer recommendations to ensure optimal efficiency.', 'category' => 'Energy Efficiency', 'icon' => 'construct-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Keep Ventilation Clear', 'message' => 'Keep ventilation areas clear where applicable, so equipment doesn\'t have to work harder than necessary.', 'category' => 'Energy Efficiency', 'icon' => 'git-network-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Upgrade to Efficient Lighting', 'message' => 'Use efficient lighting options like LEDs when replacing older, burnt-out lighting.', 'category' => 'Energy Efficiency', 'icon' => 'bulb-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Consider Building Efficiency', 'message' => 'Consider improvements that can increase overall building or room energy efficiency, like better curtains.', 'category' => 'Energy Efficiency', 'icon' => 'home-outline', 'difficulty' => 'Hard', 'savings_level' => 'High'],
    ['title' => 'Seal Air Leaks', 'message' => 'Seal obvious air leaks around windows and doors where appropriate to maintain indoor temperature efficiently.', 'category' => 'Energy Efficiency', 'icon' => 'shield-checkmark-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Improve Insulation', 'message' => 'Consider insulation improvements where appropriate to reduce overall heating and cooling needs.', 'category' => 'Energy Efficiency', 'icon' => 'layers-outline', 'difficulty' => 'Hard', 'savings_level' => 'High'],
    ['title' => 'Professional Maintenance', 'message' => 'Have major electrical or cooling systems professionally maintained when needed to restore their efficiency.', 'category' => 'Energy Efficiency', 'icon' => 'build-outline', 'difficulty' => 'Hard', 'savings_level' => 'High'],
    ['title' => 'Evaluate Long-Term Improvements', 'message' => 'Evaluate energy-efficiency improvements based on their impact on your long-term consumption trends.', 'category' => 'Energy Efficiency', 'icon' => 'analytics-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],

    // 9. Responsible Electricity Use
    ['title' => 'Switch Off When Leaving', 'message' => 'Switch off electricity immediately when leaving an unoccupied area.', 'category' => 'Responsible Electricity Use', 'icon' => 'log-out-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Avoid Unnecessary Consumption', 'message' => 'Take active steps to avoid unnecessary electricity consumption throughout the day.', 'category' => 'Responsible Electricity Use', 'icon' => 'shield-half-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Use Equipment Responsibly', 'message' => 'Use electrical equipment responsibly, ensuring it is only running when actively required.', 'category' => 'Responsible Electricity Use', 'icon' => 'hardware-chip-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Encourage Shared Responsibility', 'message' => 'Encourage everyone sharing the space to take responsibility and conserve electricity.', 'category' => 'Responsible Electricity Use', 'icon' => 'people-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Stop Purposeless Operation', 'message' => 'Avoid leaving devices operating without a clear purpose or user present.', 'category' => 'Responsible Electricity Use', 'icon' => 'stop-circle-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Perform Room Checks', 'message' => 'Check rooms thoroughly before leaving to ensure all unnecessary electricity is turned off.', 'category' => 'Responsible Electricity Use', 'icon' => 'checkbox-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Routine Conservation', 'message' => 'Make energy conservation a seamless part of your daily routine.', 'category' => 'Responsible Electricity Use', 'icon' => 'calendar-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Maintain Overall Awareness', 'message' => 'Be consistently aware of your overall electricity consumption level.', 'category' => 'Responsible Electricity Use', 'icon' => 'eye-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Use Data for Improvement', 'message' => 'Use your real consumption data to continuously improve your habits.', 'category' => 'Responsible Electricity Use', 'icon' => 'stats-chart-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Share Good Practices', 'message' => 'Share effective energy-saving practices with other occupants to multiply your impact.', 'category' => 'Responsible Electricity Use', 'icon' => 'share-social-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],

    // 10. Energy-Saving Practices
    ['title' => 'Prioritize Daylight', 'message' => 'Use natural daylight whenever practical instead of turning on interior lighting.', 'category' => 'Energy-Saving Practices', 'icon' => 'sunny-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Turn Off Unused Lights', 'message' => 'Make a firm rule to turn off unused lights in any room you are not in.', 'category' => 'Energy-Saving Practices', 'icon' => 'bulb-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Use Power Strips', 'message' => 'Use power strips to make switching off groups of electronics easier and faster where appropriate.', 'category' => 'Energy-Saving Practices', 'icon' => 'flash-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Avoid Standby Waste', 'message' => 'Avoid unnecessary standby electricity use by unplugging devices completely when not in use.', 'category' => 'Energy-Saving Practices', 'icon' => 'power-outline', 'difficulty' => 'Medium', 'savings_level' => 'Low'],
    ['title' => 'Maintain for Efficiency', 'message' => 'Maintain equipment properly to support its efficient operation over time.', 'category' => 'Energy-Saving Practices', 'icon' => 'construct-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Clear Cooling Vents', 'message' => 'Keep cooling and ventilation areas unobstructed where applicable to prevent equipment from overworking.', 'category' => 'Energy-Saving Practices', 'icon' => 'snow-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Purchase Efficient Replacements', 'message' => 'Consider energy-efficient products when purchasing replacements for old items.', 'category' => 'Energy-Saving Practices', 'icon' => 'cart-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Minimize Operating Time', 'message' => 'Avoid unnecessary operating time for any electrical equipment.', 'category' => 'Energy-Saving Practices', 'icon' => 'time-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Review Practices Regularly', 'message' => 'Review your personal electricity-saving practices regularly to see where you can improve.', 'category' => 'Energy-Saving Practices', 'icon' => 'search-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Combine Small Actions', 'message' => 'Combine several small energy-saving actions instead of relying on just one change.', 'category' => 'Energy-Saving Practices', 'icon' => 'git-merge-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],

    // 11. Consumption Goals
    ['title' => 'Set Realistic Monthly Goals', 'message' => 'Set a realistic monthly electricity-consumption goal based on your past data.', 'category' => 'Consumption Goals', 'icon' => 'flag-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Compare with Past Periods', 'message' => 'Compare your current consumption with your previous period to set an appropriate target.', 'category' => 'Consumption Goals', 'icon' => 'swap-horizontal-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Set a Weekly Reduction Goal', 'message' => 'Set a weekly goal specifically for reducing unnecessary electricity usage.', 'category' => 'Consumption Goals', 'icon' => 'calendar-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Track via Wattipid', 'message' => 'Consistently track your progress towards your goals through the Wattipid dashboard.', 'category' => 'Consumption Goals', 'icon' => 'analytics-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Avoid Unrealistic Targets', 'message' => 'Avoid setting unrealistic reduction targets that might be impossible to maintain long-term.', 'category' => 'Consumption Goals', 'icon' => 'alert-circle-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Review Goals Regularly', 'message' => 'Review and adjust your goal when your daily routine or consumption pattern significantly changes.', 'category' => 'Consumption Goals', 'icon' => 'sync-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Use Actual Data', 'message' => 'Always use your actual past consumption data when setting future goals.', 'category' => 'Consumption Goals', 'icon' => 'stats-chart-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Monitor Consistent Improvements', 'message' => 'Monitor your dashboard for consistent improvements in your overall consumption.', 'category' => 'Consumption Goals', 'icon' => 'trending-down-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Identify Helpful Habits', 'message' => 'Identify the specific habits that actively help you maintain lower consumption and reinforce them.', 'category' => 'Consumption Goals', 'icon' => 'checkmark-circle-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Update Long-Term Goals', 'message' => 'Update your goals based on your long-term consumption trend rather than a single good day.', 'category' => 'Consumption Goals', 'icon' => 'calendar-number-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],

    // 12. Energy Awareness
    ['title' => 'Learn Your Trends', 'message' => 'Learn how your overall electricity consumption naturally changes over time.', 'category' => 'Energy Awareness', 'icon' => 'bar-chart-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Check Regularly, Not Just at Billing', 'message' => 'Check your consumption regularly on Wattipid instead of waiting until your bill arrives.', 'category' => 'Energy Awareness', 'icon' => 'eye-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Notice Unusual Changes', 'message' => 'Pay close attention to any unusual changes in your overall consumption pattern.', 'category' => 'Energy Awareness', 'icon' => 'alert-circle-outline', 'difficulty' => 'Medium', 'savings_level' => 'Moderate'],
    ['title' => 'Compare Different Periods', 'message' => 'Compare your daily, weekly, and monthly electricity usage to get a complete picture.', 'category' => 'Energy Awareness', 'icon' => 'pie-chart-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Understand Habit Impact', 'message' => 'Understand that your total consumption fluctuates depending directly on your usage habits.', 'category' => 'Energy Awareness', 'icon' => 'bulb-outline', 'difficulty' => 'Easy', 'savings_level' => 'Moderate'],
    ['title' => 'Become More Aware', 'message' => 'Use Wattipid analytics consistently to become more aware of your electricity usage.', 'category' => 'Energy Awareness', 'icon' => 'analytics-outline', 'difficulty' => 'Easy', 'savings_level' => 'High'],
    ['title' => 'Discuss with Occupants', 'message' => 'Discuss energy-saving practices and overall awareness with other occupants.', 'category' => 'Energy Awareness', 'icon' => 'chatbubbles-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Make Informed Decisions', 'message' => 'Make informed decisions about your energy habits based on actual consumption data.', 'category' => 'Energy Awareness', 'icon' => 'information-circle-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
    ['title' => 'Remember the Total Focus', 'message' => 'Remember that Wattipid measures total electricity consumption, giving you a holistic view of your habits.', 'category' => 'Energy Awareness', 'icon' => 'hardware-chip-outline', 'difficulty' => 'Easy', 'savings_level' => 'Low'],
    ['title' => 'Develop Long-Term Habits', 'message' => 'Use your consumption history to develop better, long-term energy awareness and habits.', 'category' => 'Energy Awareness', 'icon' => 'calendar-outline', 'difficulty' => 'Medium', 'savings_level' => 'High'],
];

echo "Wattipid Generalized Tips Database Seeder\n";
echo "========================================\n\n";

try {
    $conn->beginTransaction();

    // Wipe existing tips completely
    $conn->exec("DELETE FROM electricity_tips");
    echo "Deleted existing tips from the database.\n";

    // Prepare statement for insertion
    $stmt = $conn->prepare("INSERT INTO electricity_tips (title, message, category, icon, difficulty, savings_level) VALUES (?, ?, ?, ?, ?, ?)");
    
    $insertedCount = 0;
    foreach ($tips as $tip) {
        $stmt->execute([
            $tip['title'],
            $tip['message'],
            $tip['category'],
            $tip['icon'] ?? 'bulb-outline',
            $tip['difficulty'] ?? 'Easy',
            $tip['savings_level'] ?? 'Moderate'
        ]);
        $insertedCount++;
    }

    $conn->commit();
    echo "\nSuccessfully inserted {$insertedCount} generalized energy-saving tips across 12 categories!\n";
    echo "Tip generation complete.\n";

} catch (Exception $e) {
    $conn->rollBack();
    echo "Error seeding tips: " . $e->getMessage() . "\n";
}
