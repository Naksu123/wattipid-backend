-- ==========================================
-- WATTIPID 40 HIGH-QUALITY DORMITORY TIPS
-- ==========================================

DROP TABLE IF EXISTS electricity_tips;

CREATE TABLE electricity_tips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    category VARCHAR(100) NOT NULL,
    icon VARCHAR(50) DEFAULT 'bulb-outline',
    difficulty ENUM('Easy','Medium','Hard') DEFAULT 'Easy',
    savings_level ENUM('Low','Moderate','High') DEFAULT 'Low',
    dorm_relevance ENUM('Student','Apartment','Boarding House') DEFAULT 'Student',
    is_active TINYINT(1) DEFAULT 1,
    views_count INT DEFAULT 0,
    likes_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO electricity_tips (title, message, category, icon, difficulty, savings_level, dorm_relevance) VALUES
('Unplug the Rice Cooker', 'The ''Keep Warm'' function on rice cookers consumes almost as much power as cooking. Unplug it as soon as the rice is done.', 'Kitchen Appliances', 'restaurant-outline', 'Easy', 'High', 'Boarding House'),
('Clean Your Electric Fan Blades', 'Dust buildup makes the fan motor work harder and spin slower. Clean the blades and grills monthly for maximum breeze.', 'Fan Usage', 'sync-outline', 'Medium', 'Low', 'Student'),
('Don''t Overcharge Laptops', 'Unplug your laptop once it reaches 100%. Continuous overnight charging wastes phantom power and degrades your battery.', 'Charging Devices', 'battery-charging-outline', 'Easy', 'Moderate', 'Student'),
('Defrost the Mini Fridge', 'Thick ice buildup in the freezer forces the compressor to run nonstop. Defrost it whenever ice gets thicker than a coin.', 'Refrigerator Usage', 'snow-outline', 'Medium', 'High', 'Apartment'),
('Share the Fridge Space', 'Consolidate your food with roommates instead of running two separate mini-fridges in one small room.', 'Shared Room Efficiency', 'people-outline', 'Hard', 'High', 'Apartment'),
('Use Sleep Mode on Gaming PCs', 'Don''t leave your rig running while going to class. Put it to sleep or shut it down completely.', 'Gaming & Entertainment', 'game-controller-outline', 'Easy', 'High', 'Student'),
('Unplug Extension Cords', 'Even empty extension cords with indicator lights consume phantom power. Unplug the main cord when you leave for school.', 'Daily Habits', 'calendar-outline', 'Easy', 'Low', 'Boarding House'),
('Switch to LED Desk Lamps', 'Stop using old incandescent bulbs for late-night studying. LED lamps provide brighter light for 90% less energy.', 'Study Setup', 'book-outline', 'Easy', 'Moderate', 'Student'),
('Coordinate Laundry Days', 'Wait until you have a full load before using a shared washing machine. Washing a few shirts wastes water and power.', 'Laundry', 'shirt-outline', 'Medium', 'Moderate', 'Apartment'),
('Air Dry Your Clothes', 'Hang clothes near the window instead of using electric spin dryers. The Philippines is hot enough to dry clothes naturally.', 'Laundry', 'shirt-outline', 'Medium', 'High', 'Boarding House'),
('Use Inverter Aircons if Possible', 'If you have a choice, rent a room with an inverter AC. They use 30-50% less energy for long overnight usage.', 'Air Conditioning', 'snow-outline', 'Hard', 'High', 'Apartment'),
('Close Windows When AC is On', 'A basic rule, but leaving gaps or the door cracked lets cool air escape, making the compressor work double time.', 'Air Conditioning', 'snow-outline', 'Easy', 'Moderate', 'Student'),
('Set AC to 24-26 Degrees', 'This is the sweet spot. Setting it to 16 degrees doesn''t cool the room faster, it just guarantees a massive electric bill.', 'Air Conditioning', 'snow-outline', 'Easy', 'High', 'Apartment'),
('Use the AC Timer', 'Set the AC to turn off automatically at 3 AM. The room will stay cold enough until you wake up for your morning classes.', 'Air Conditioning', 'snow-outline', 'Easy', 'High', 'Student'),
('Combine AC and Fan', 'Run the AC to quickly cool the room, then switch to an electric fan to circulate the cold air and maintain the temperature.', 'Fan Usage', 'sync-outline', 'Medium', 'High', 'Boarding House'),
('Avoid Overstuffing the Fridge', 'Cold air needs to circulate to keep things cool efficiently. A crammed fridge has to work harder.', 'Refrigerator Usage', 'snow-outline', 'Medium', 'Moderate', 'Student'),
('Cook in Batches', 'Cook your rice once for the whole day instead of plugging in the cooker three separate times.', 'Kitchen Appliances', 'restaurant-outline', 'Medium', 'Moderate', 'Apartment'),
('Boil Only What You Need', 'Don''t fill the electric kettle to the brim if you just want one cup of instant coffee.', 'Kitchen Appliances', 'restaurant-outline', 'Easy', 'Low', 'Student'),
('Turn Off Monitors', 'Turn off your external monitor screen when stepping out for a quick snack or bathroom break.', 'Gaming & Entertainment', 'game-controller-outline', 'Easy', 'Low', 'Student'),
('Limit Heavy Gaming During Peak Hours', 'Generating massive heat from your PC means your AC or fan needs to work harder to cool the room.', 'Gaming & Entertainment', 'game-controller-outline', 'Medium', 'Moderate', 'Apartment'),
('Avoid Ironing Wet Clothes', 'It takes significantly more electricity to iron out damp uniforms. Make sure they are completely dry first.', 'Laundry', 'shirt-outline', 'Medium', 'Moderate', 'Student'),
('Iron Once a Week', 'Iron all your uniforms in one batch on Sunday rather than piece by piece daily to save heating energy.', 'Laundry', 'shirt-outline', 'Medium', 'Moderate', 'Boarding House'),
('Clean AC Filters Monthly', 'A clogged AC filter reduces airflow and spikes consumption. Wash it in the sink every month.', 'Appliance Maintenance', 'build-outline', 'Medium', 'High', 'Apartment'),
('Unplug Chargers When Empty', 'Chargers left plugged into the wall without a phone attached still consume tiny amounts of phantom power.', 'Charging Devices', 'battery-charging-outline', 'Easy', 'Low', 'Student'),
('Use Power Strips with Switches', 'Easily turn off your monitor, speakers, and PC with one click before leaving for campus.', 'Study Setup', 'book-outline', 'Easy', 'Moderate', 'Boarding House'),
('Study Together', 'Share one bright room light instead of turning on multiple individual desk lamps when studying with roommates.', 'Shared Room Efficiency', 'people-outline', 'Easy', 'Low', 'Student'),
('Turn Off the Water Heater', 'Don''t leave the shower heater on standby. Turn it on 5 minutes before you shower and turn it off immediately after.', 'Daily Habits', 'calendar-outline', 'Easy', 'Moderate', 'Apartment'),
('Lock the Fan Direction', 'If you are studying alone, stop the fan from oscillating. Direct airflow keeps you cooler, so you won''t need the AC.', 'Fan Usage', 'sync-outline', 'Easy', 'Low', 'Student'),
('Place the Fridge in a Cool Spot', 'Keep your mini-fridge away from direct sunlight or the electric stove to prevent it from overheating.', 'Refrigerator Usage', 'snow-outline', 'Medium', 'Moderate', 'Apartment'),
('Don''t Leave the Fridge Open', 'Decide what you want to eat before opening the door. Standing there staring lets all the cold air out.', 'Refrigerator Usage', 'snow-outline', 'Easy', 'Low', 'Student'),
('Use Dark Mode', 'Dark mode on OLED phone and laptop screens uses less battery, meaning you charge less frequently.', 'Charging Devices', 'battery-charging-outline', 'Easy', 'Low', 'Student'),
('Charge Phones During the Day', 'Avoid leaving phones plugged in overnight where they overcharge for 6+ hours while you sleep.', 'Charging Devices', 'battery-charging-outline', 'Easy', 'Low', 'Student'),
('Turn Off Wi-Fi Router at Night', 'If everyone is asleep, switch off the dorm internet router. It saves a small but steady amount of power.', 'Shared Room Efficiency', 'people-outline', 'Easy', 'Low', 'Apartment'),
('Use Thick Curtains', 'Block out the intense afternoon sun. Keeping the heat out means your AC doesn''t have to fight it later.', 'Daily Habits', 'calendar-outline', 'Medium', 'Moderate', 'Boarding House'),
('Soak Beans and Meat', 'Soak tough food items before cooking so the electric stove runs for less time to boil them soft.', 'Kitchen Appliances', 'restaurant-outline', 'Medium', 'Moderate', 'Apartment'),
('Cover Pots While Cooking', 'Food cooks faster when heat is trapped inside the pot, saving electricity on your induction cooker.', 'Kitchen Appliances', 'restaurant-outline', 'Easy', 'Low', 'Boarding House'),
('Wipe the Stove Burner', 'A clean electric burner transfers heat to your pan much more efficiently than a crusty, dirty one.', 'Appliance Maintenance', 'build-outline', 'Medium', 'Low', 'Apartment'),
('Keep Devices Cool', 'Don''t use laptops on beds. Poor ventilation makes the internal fans spin faster, using more battery power.', 'Study Setup', 'book-outline', 'Easy', 'Low', 'Student'),
('Optimize PC Power Plans', 'Change your Windows setting to ''Power Saver'' when you are just typing an essay or reading PDFs.', 'Study Setup', 'book-outline', 'Easy', 'Moderate', 'Student'),
('Check for Faulty Wiring', 'Warm plugs or flickering lights indicate inefficiency. Report them to your landlord immediately.', 'Appliance Maintenance', 'build-outline', 'Medium', 'High', 'Boarding House');
