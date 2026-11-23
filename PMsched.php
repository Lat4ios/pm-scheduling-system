<?php
/*
 Preventive Maintenance Electronic System - single-file PHP application for XAMPP
 Put this file in C:\\xampp\\htdocs\\pm_schedule\\index.php, then open
 http://localhost/pm_schedule/
 The application creates pm_schedule_data.json beside this file.
*/
declare(strict_types=1);

// PHPMailer is installed during the Docker image build.
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) { require_once $composerAutoload; }
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
// All system timestamps use the configured company timezone (Philippine Time by default).
date_default_timezone_set(getenv('PM_TIMEZONE') ?: 'Asia/Manila');

// On Render, keep all mutable data on the attached persistent disk.
$dataDirectory = rtrim(getenv('PM_DATA_DIR') ?: __DIR__, DIRECTORY_SEPARATOR);
define('DATA_FILE', $dataDirectory . '/pm_schedule_data.json');
define('USER_PHOTO_DIR', $dataDirectory . '/pm_user_photos');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
const APP_YEAR = 2026;
const SOURCE_REVISION = '2026-09-02-with-due-dates';
const SCHEDULE_REFERENCE_REVISION = '2026-10-21-excel-revision-2-full-frequency';
const RCD_FREQUENCY_REVISION = '2026-10-22-qh-only';
// Weeks 1-35 are closed from paper records; WW36 remains open for due-date entry.
const PAPER_RECORDS_COMPLETED_THROUGH = '2026-08-30';

function sourceRows(): array {
    return [
        ['WAFER MOUNTER(NITTO)','WMN-001','WWWMWWWMWWWMWWWMWWWMWWWHWWWMWWWMWWWMWWWMWWWMWWWMWWWH'],
        ['WAFER SAW (DFD 6362)','WSD-001','WWWMWWWMWWWMWWWMWWWMWWWHWWWMWWWMWWWMWWWQWWWMWWWMWWWA'],
        ['WAFER SAW (DFD 6240) TAIKO','RCD-001','-----------Q-----------H-----------Q-----------H----'],
        ['DWR 1722 (DISCO) WATER RECYCLING UNIT','WRD-001','---M---M---Q---M---M---H---M---M---Q---M---M---A---M'],
        ['UV CURE (LINTEC)','UVL-001','-----------Q-----------Q-----------Q-----------Q----'],
        ['DIE ATTACH -ASM AD838 -1','DBA - 001','MWWWMWWWMWWWMWWWMWWWHWWWMWWWMWWWMWWWMWWWMWWWHWWWMWWW'],
        ['DIE ATTACH -ASM AD838 -2','DBA - 002','WMWWWMWWWMWWWMWWWMWWWHWWWMWWWMWWWMWWWMWWWMWWWHWWWMWW'],
        ['DIE ATTACH -ASM AD838 -3','DBA - 003','WWMWWWMWWWMWWWMWWWMWWWHWWWMWWWMWWWMWWWMWWWMWWWHWWWMW'],
        ['DIE ATTACH -ASM AD838 -4','DBA - 004','WWWMWWWMWWWMWWWMWWWMWWWHWWWMWWWMWWWMWWWMWWWMWWWHWWWM'],
        ['DIE ATTACH -ASM AD838 -5','DBA - 005','WWWMWWWMWWWMWWWMWWWMWWWHWWWMWWWMWWWMWWWMWWWMWWWHWWWM'],
        ['REFLOW OVEN RO 832','ROA-001','WWWMWWWMWWWQWWWMWWWMWWWHWWWMWWWMWWWQWWWMWWWMWWWAWWWM'],
        ['DIE CURE OVEN (BLUE M 01)','OVB-001','---M---M---M---M---M---H---M---M---M---M---M---H---M'],
        ['PLASMA CLEANER - 1','PLV-001','WWWMWWWMWWWMWWWMWWWMWWWHWWWMWWWMWWWMWWWMWWWMWWWHWWWM'],
        ['FLUX CLEAN (SC 700)','FCG-001','WWWMWWWMWWWQWWWMWWWMWWWHWWWMWWWMWWWQWWWMWWWMWWWAWWWM'],
        ['WEDGE BONDER-AL WIRE (POWER C PLUS)','WBAK-001','WWWMWWWMWWWQWWWMWWWMWWWHWWWMWWWMWWWQWWWMWWWMWWWAWWWM'],
        ['BALL BONDER KS','WBK-001','WWWMWWWMWWWQWWWMWWWMWWWHWWWMWWWMWWWQWWWMWWWMWWWAWWWM'],
    ];
}

function normalizePattern(string $pattern): array {
    $chars = preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($chars as $char) {
        if (count($out) >= 52) break;
        $out[] = in_array($char, ['W','M','Q','H','A'], true) ? $char : '';
    }
    return array_pad($out, 52, '');
}

function plannedDueDates(): array {
    return ['WMN-001'=>'2026-09-09','WSD-001'=>'2026-09-04','RCD-001'=>'2026-09-20','WRD-001'=>'2026-09-27','UVL-001'=>'2026-09-28','DBA - 001'=>'2026-09-07','DBA - 002'=>'2026-09-08','DBA - 003'=>'2026-09-02','DBA - 004'=>'2026-09-03','DBA - 005'=>'2026-09-05','ROA-001'=>'2026-09-05','OVB-001'=>'2026-09-18','PLV-001'=>'2026-09-03','FCG-001'=>'2026-09-08','WBAK-001'=>'2026-09-04','WBK-001'=>'2026-09-08'];
}

function plannedFrequencies(): array {
    return ['WMN-001'=>'W','WSD-001'=>'W','RCD-001'=>'Q','WRD-001'=>'H','UVL-001'=>'Q','DBA - 001'=>'W','DBA - 002'=>'W','DBA - 003'=>'W','DBA - 004'=>'W','DBA - 005'=>'W','ROA-001'=>'W','OVB-001'=>'M','PLV-001'=>'W','FCG-001'=>'W','WBAK-001'=>'W','WBK-001'=>'W'];
}

function plvChecklistItems(): array {
    $weeklyMonthlyHalf=['W','M','H']; $monthlyHalf=['M','H']; $half=['H'];
    return [
        1=>['item'=>'INDICATOR LIGHT — Check the status of the machine indicator light. Replace it promptly if damaged.','levels'=>$weeklyMonthlyHalf],
        2=>['item'=>'INNER CHAMBER CLEANING — Use the standard PM program to clean the inner chamber.','levels'=>$weeklyMonthlyHalf],
        3=>['item'=>'EJECTOR ROD — Check whether the ejector rod is deformed. Replace it promptly if deformation is found.','levels'=>$weeklyMonthlyHalf],
        4=>['item'=>'EXHAUST OIL LEAKAGE — Open the vacuum pump in manual mode to evacuate the reaction chamber. Close the vacuum pump and check whether the pressure rises by more than 0.1 Torr within 30 seconds. Confirm the condition of the reaction-chamber sealing ring and air tubing.','levels'=>$monthlyHalf],
        5=>['item'=>'CHAMBER LEAK — Check the exhaust for oil leakage. Stop and repair any leak promptly. Loosen the relief screw, drain the waste oil from the pump, then reinstall and tighten the screw (oil pump only).','levels'=>$monthlyHalf],
        6=>['item'=>'PUMP OIL LEVEL — Make sure the oil level is above one-half. If it is low, refill it to two-thirds of the oil-level mark (oil pump only).','levels'=>$monthlyHalf],
        7=>['item'=>'FEED BELT — Check the condition of the feed belt (VSP-88D PRO1 only).','levels'=>$monthlyHalf],
        8=>['item'=>'GASKET — Check sealing tightness. Replace the gasket if damaged or defective.','levels'=>$half],
        9=>['item'=>'OIL MIST TRAP — Replace the oil mist trap if it is seriously leaking.','levels'=>$half],
        10=>['item'=>'VACUUM PUMP — Drain the residual oil, add new oil to the lower oil-level limit, and run the cleaning procedure. Repeat two or three times until the discharged oil returns to its normal color, then fill to two-thirds of the oil-level mark.','levels'=>$half],
        11=>['item'=>'FILTER CHECK — Check whether the inlet filter is contaminated and replace it when necessary.','levels'=>$half],
        12=>['item'=>'CAVITY ORBIT — Check the track-wall condition and confirm that the inner wall is smooth (VSP-88D PRO1 only).','levels'=>$half],
        13=>['item'=>'DRIVE BELT, DRIVE SHAFT AND GEAR — Check the transmission shaft and gear condition and confirm they are not damaged (VSP-88D PRO1 only).','levels'=>$half],
        14=>['item'=>'ESD RESISTANCE — Confirm: (1) rail-to-loader resistance is below 10 ohms; (2) rail-to-unloader resistance is below 10 ohms; (3) loader-to-unloader resistance is below 10 ohms (VSP-88H excepted); and (4) ground-to-chamber-door lock-latch resistance is below 10 ohms.','levels'=>$half],
    ];
}

function plvChecklistForFrequency(string $frequency): array {
    return array_filter(plvChecklistItems(),fn($row)=>checklistItemAppliesToFrequency($row['levels']??[],$frequency));
}

function checklistItemAppliesToFrequency(array $itemFrequencies, string $scheduledFrequency): bool {
    // Higher-level PM includes every lower-frequency checklist:
    // M=W+M, Q=W+M+Q, H=W+M+Q+H, A=all.
    $rank=['W'=>1,'M'=>2,'Q'=>3,'H'=>4,'A'=>5];
    if (!isset($rank[$scheduledFrequency])) return false;
    $lowestRequiredRank=null;
    foreach ($itemFrequencies as $frequency) {
        if (isset($rank[$frequency])) $lowestRequiredRank=$lowestRequiredRank===null ? $rank[$frequency] : min($lowestRequiredRank,$rank[$frequency]);
    }
    return $lowestRequiredRank!==null && $lowestRequiredRank<=$rank[$scheduledFrequency];
}

function powerCPlusChecklistItems(): array {
    $all=['W','M','Q','H','A']; $monthlyUp=['M','Q','H','A']; $quarterlyUp=['Q','H','A']; $halfYearlyUp=['H','A']; $annual=['A'];
    return [
        ['item'=>'Replace Wire Guide Sleeve of Bond Head and Dereeler','levels'=>$all],
        ['item'=>'Clean Transport and Handler Assemblies and Magazine Handlers','levels'=>$all],
        ['item'=>'Clean Dereeler Rollers','levels'=>$monthlyUp],
        ['item'=>'Forcer Calibration and Force Check (Bond Head Tab)','levels'=>$monthlyUp],
        ['item'=>'Clean Bond Head Wire Clamp Jaws','levels'=>$quarterlyUp],
        ['item'=>'Check Emergency Stop Switch Circuitry','levels'=>$halfYearlyUp],
        ['item'=>'Clean Electronics Cabinet','levels'=>$monthlyUp],
        ['item'=>'Clean Motion Module Drive Card Cage Assembly','levels'=>$monthlyUp],
        ['item'=>'Clean Transport Card Cage Assembly','levels'=>$monthlyUp],
        ['item'=>'Clean Pneumatics Panel Assembly','levels'=>$monthlyUp],
        ['item'=>'Check Moving Gripper Lubrication','levels'=>$monthlyUp],
        ['item'=>'Check Clamp Station Lubrication','levels'=>$monthlyUp],
        ['item'=>'Inspect Input Indexer for Wear and Damage','levels'=>$monthlyUp],
        ['item'=>'Inspect Output Indexer for Wear and Damage','levels'=>$monthlyUp],
        ['item'=>'Inspect Clamp Motor for Wear and Damage','levels'=>$monthlyUp],
        ['item'=>'Inspect Input Pusher for Wear and Damage','levels'=>$halfYearlyUp],
        ['item'=>'Inspect Magazine Handler Load Trays for Wear and Damage','levels'=>$halfYearlyUp],
        ['item'=>'Magnification Calibration (Optical System Tab)','levels'=>$annual],
        ['item'=>'Limits Calibration (Positioning System Tab)','levels'=>$annual],
        ['item'=>'Bond Head Light Intensity Calibration (Optical System Tab)','levels'=>$annual],
        ['item'=>'Check Voltages','levels'=>$annual],
        ['item'=>'Clean Operator Controls Assembly (Trackball, Control Panel and Keyboard)','levels'=>$annual],
        ['item'=>'Replace Wire Feed Sensor Tension Spring','levels'=>$annual],
        ['item'=>'Clean Buffer Sensor Plates','levels'=>$annual],
        ['item'=>'Lubricate X-Axis Bearings','levels'=>$annual],
        ['item'=>'Lubricate Y-Axis Bearings','levels'=>$annual],
        ['item'=>'Lubricate Z-Tower','levels'=>$annual],
        ['item'=>'Lubricate Input Pusher Linear Bearing','levels'=>$annual],
    ];
}

function powerNexxChecklistItems(): array {
    $all=['W','M','Q','H','A']; $monthlyUp=['M','Q','H','A']; $quarterlyUp=['Q','H','A']; $halfYearlyUp=['H','A']; $annual=['A'];
    return [
        ['item'=>'Clean Wire Feed Air Guide','levels'=>$all],
        ['item'=>'Clean MMI and Mouse','levels'=>$all],
        ['item'=>'Examine and Clean Monitor','levels'=>$all],
        ['item'=>'Examine EFO Setup and Condition','levels'=>$all],
        ['item'=>'Clean XY Amplifier Filter (Non-Cleanroom Environment)','levels'=>$all],
        ['item'=>'Clean Z-Node Filter (Non-Cleanroom Environment)','levels'=>$all],
        ['item'=>'Replace Magazine Stop Buttons','levels'=>$all],
        ['item'=>'Examine Heater Block and Check Bonding Temperature','levels'=>$all],
        ['item'=>'Test Emergency OFF Switch Function','levels'=>$all],
        ['item'=>'Clean Wire Clamp Jewels','levels'=>$all],
        ['item'=>'Check Cable Connections','levels'=>$monthlyUp],
        ['item'=>'Z-Axis Calibration','levels'=>$monthlyUp],
        ['item'=>'Check Power Supply Voltage','levels'=>$monthlyUp],
        ['item'=>'Clean All Electrical Connection Areas','levels'=>$monthlyUp],
        ['item'=>'Check Wire Feed System','levels'=>$monthlyUp],
        ['item'=>'Clean Wire Feed System','levels'=>$monthlyUp],
        ['item'=>'Check Bonding Head Alignment','levels'=>$monthlyUp],
        ['item'=>'Check Bonding Head Calibration','levels'=>$monthlyUp],
        ['item'=>'Clean Bonding Head','levels'=>$monthlyUp],
        ['item'=>'Check Ultrasonic Frequency','levels'=>$monthlyUp],
        ['item'=>'Check Ultrasonic Amplitude','levels'=>$monthlyUp],
        ['item'=>'Check Transducer / USG Impedance','levels'=>$monthlyUp],
        ['item'=>'Check Thermosonic Power','levels'=>$monthlyUp],
        ['item'=>'Clean Thermosonic Welder','levels'=>$monthlyUp],
        ['item'=>'Check Vision System Alignment','levels'=>$monthlyUp],
        ['item'=>'Clean Vision System','levels'=>$monthlyUp],
        ['item'=>'Check Vision System Focus','levels'=>$monthlyUp],
        ['item'=>'Check Air Pressure Label and Gauge Condition','levels'=>$monthlyUp],
        ['item'=>'Check Vacuum Pressure Label and Gauge Condition','levels'=>$monthlyUp],
        ['item'=>'Check and Clean Sensors as Needed','levels'=>$monthlyUp],
        ['item'=>'Check Gas Pressure Label and Gauge Condition','levels'=>$monthlyUp],
        ['item'=>'Clean Window Clamp and Heater Block','levels'=>$monthlyUp],
        ['item'=>'Check for Proper Grounding','levels'=>$monthlyUp],
        ['item'=>'Inspect Air and Vacuum Hoses','levels'=>$quarterlyUp],
        ['item'=>'Inspect Attaching Hardware and Connectors','levels'=>$quarterlyUp],
        ['item'=>'Check Card Cage Air Filter and Replace as Needed','levels'=>$quarterlyUp],
        ['item'=>'Clean XY Amplifier Filter (Cleanroom)','levels'=>$quarterlyUp],
        ['item'=>'Examine Heater Block and Window Clamper for Damage','levels'=>$quarterlyUp],
        ['item'=>'Clean Z-Node (Cleanroom)','levels'=>$quarterlyUp],
        ['item'=>'Check DC Outputs','levels'=>$halfYearlyUp],
        ['item'=>'Check Z Encoder Signal Amplitude','levels'=>$halfYearlyUp],
        ['item'=>'Check Transducer Alignment','levels'=>$halfYearlyUp],
        ['item'=>'Clean X and Y Servo Motors','levels'=>$halfYearlyUp],
        ['item'=>'Examine and Replace Power Supply Fan Filters','levels'=>$halfYearlyUp],
        ['item'=>'Check X and Y Motor Cooling Air Tubing Condition','levels'=>$halfYearlyUp],
        ['item'=>'Lubricate X Slide, Front Y and Rear Y Slide Bearings','levels'=>$annual],
        ['item'=>'Lubricate Rear Y Coupling Bearing','levels'=>$annual],
        ['item'=>'Check Ground Strap','levels'=>$annual],
    ];
}

function ad838ChecklistItems(): array {
    // Frequency assignments are taken directly from DBA-001.xlsx:
    // items 1-9 Weekly, items 10-17 Monthly, and items 18-28 Half-Yearly.
    return [
        ['item'=>'Cleaning M/C','levels'=>['W']],
        ['item'=>'Pick & Place Bond Force','levels'=>['W']],
        ['item'=>'Missing Die Sensor','levels'=>['W']],
        ['item'=>'Bond Head Touch Sensor','levels'=>['W']],
        ['item'=>'Ejector Cap Vacuum Rubber Band','levels'=>['W']],
        ['item'=>'Cleaning Ejector Cap','levels'=>['W']],
        ['item'=>'Check PR Light','levels'=>['W']],
        ['item'=>'Check Furnace Temperature Setting','levels'=>['W']],
        ['item'=>'Cleaning of Oven Exhaust Pipes','levels'=>['W']],
        ['item'=>'Check and Adjust Bond Head Horizontal Leveling','levels'=>['M']],
        ['item'=>'Index Clamp and Roller Track','levels'=>['M']],
        ['item'=>'Emergency Stop','levels'=>['M']],
        ['item'=>'Clean Ion Fans','levels'=>['M']],
        ['item'=>'Check and Clean Metal Clamp','levels'=>['M']],
        ['item'=>'Check the Wafer Table Anti-Reverse Device','levels'=>['M']],
        ['item'=>'Check Oven Temperature','levels'=>['M']],
        ['item'=>'Check Filter System Status and Confirm the Filter System Is Clear (Blockless)','levels'=>['M']],
        ['item'=>'Wafer Table PM / Chipstand Maintenance','levels'=>['H']],
        ['item'=>'Dispenser PM / Dispensing System Maintenance','levels'=>['H']],
        ['item'=>'Input & Output Elevator PM / Feed and Discharge System Maintenance','levels'=>['H']],
        ['item'=>'Dispenser Standby Force / Dispenser Pressure (300-400 g)','levels'=>['H']],
        ['item'=>'Dispenser Horizontal Leveling / Dispenser Level','levels'=>['H']],
        ['item'=>'Index Anvil Block Horizontal Leveling / Track Pad Horizontal Contrast (<= 100 um or 200 cnt)','levels'=>['H']],
        ['item'=>'Bond Force Calibration / Correction Bond Force','levels'=>['H']],
        ['item'=>'Oven PM / Stove Maintenance','levels'=>['H']],
        ['item'=>'Filter PM / Filter Care','levels'=>['H']],
        ['item'=>'Reflow Oven Steel Wire Tension Calibration / Correct the Steel-Wire Tightness in the Reflow Furnace','levels'=>['H']],
        ['item'=>'Replace the Filter Core / Replace Filter','levels'=>['H']],
    ];
}

function fcgSc700ChecklistItems(): array {
    return [
        ['item'=>'Check and Clean Filters and Strainers','levels'=>['W']],
        ['item'=>'Inspect and Clean Spray Nozzles for Clogging','levels'=>['W']],
        ['item'=>'Verify Air Pressure and Adjust if Necessary','levels'=>['W']],
        ['item'=>'Check Ultrasonic Transducers for Unusual Noise or Vibration','levels'=>['W']],
        ['item'=>'Wipe Down Exterior Surfaces to Remove Dust and Residues','levels'=>['W']],
        ['item'=>'Drain Tank if Required by the Process and Clean Debris','levels'=>['W']],
        ['item'=>'Inspect Electrical Connections for Looseness or Corrosion','levels'=>['M']],
        ['item'=>'Check the Control Panel for Error Codes or Warning Indicators','levels'=>['M']],
        ['item'=>'Test Safety Devices, Emergency Stop and Alarms','levels'=>['M']],
        ['item'=>'Inspect Tank Lining for Wear, Cracks or Leaks','levels'=>['M']],
        ['item'=>'Verify Water Level and Replenishment System','levels'=>['M']],
        ['item'=>'Lubricate Moving Parts as Specified in the Lubrication Chart','levels'=>['M']],
        ['item'=>'Measure and Record Ultrasonic Power Output to Detect Degradation','levels'=>['Q']],
        ['item'=>'Inspect and Tighten Mechanical Fasteners, Frame, Mounts and Covers','levels'=>['Q']],
        ['item'=>'Test Heating Elements for Correct Operation and Uniform Heating','levels'=>['Q']],
        ['item'=>'Check Insulation Resistance of Electrical Components','levels'=>['Q']],
        ['item'=>'Inspect Pneumatic System, Hoses, Valves and Pressure Regulators','levels'=>['Q']],
        ['item'=>'Perform Partial Calibration of Temperature and Pressure Sensors','levels'=>['Q']],
        ['item'=>'Conduct Full Inspection of the Ultrasonic Generator, Including IGBT Modules, Capacitors and Cooling Fans','levels'=>['H']],
        ['item'=>'Replace Worn Filters, Gaskets and Seals','levels'=>['H']],
        ['item'=>'Check Vibration Isolators and Mounts','levels'=>['H']],
        ['item'=>'Oil or Grease Critical Bearings if Applicable','levels'=>['H']],
        ['item'=>'Inspect Water and Air Piping for Leaks and Corrosion','levels'=>['H']],
        ['item'=>'Verify Grounding and Electrical Safety Compliance','levels'=>['H']],
        ['item'=>'Perform Full System Calibration and Performance Testing','levels'=>['A']],
        ['item'=>'Replace Critical Wear Components, Including Degraded Transducers or Heating Elements','levels'=>['A']],
        ['item'=>'Perform Insulation Resistance and Dielectric Strength Testing','levels'=>['A']],
        ['item'=>'Drain and Thoroughly Deep-Clean Internal Tanks','levels'=>['A']],
        ['item'=>'Overhaul Pneumatic and Hydraulic Systems if Used','levels'=>['A']],
        ['item'=>'Inspect Structural Integrity of the Frame and Tank Welds','levels'=>['A']],
    ];
}

function roaR0832ChecklistItems(): array {
    return [
        ['item'=>'Inspect the Reflow Interior and Exterior for Dust, Debris or Contamination and Clean as Needed','levels'=>['W']],
        ['item'=>'Check Exhaust-System Filters, Fans and Ducts; Clean or Replace Filters and Confirm Proper Airflow','levels'=>['W']],
        ['item'=>'Inspect Control-Panel Buttons, Displays and Indicators; Clean or Replace Components as Needed','levels'=>['W']],
        ['item'=>'Check Cooling-System Fans, Filters and Heat Exchangers; Clean or Replace Components as Needed','levels'=>['M']],
        ['item'=>'Verify Temperature-Control Accuracy and Stability Using a Temperature Profiler or Thermocouples','levels'=>['M']],
        ['item'=>'Inspect Conveyor Belts, Chains and Rollers; Lubricate or Replace Components as Needed','levels'=>['M']],
        ['item'=>'Thoroughly Clean the Reflow Interior, Heating Elements, Cooling System and Conveyor System','levels'=>['Q']],
        ['item'=>'Replace Worn or Damaged Components Such as Heating Elements, Fans or Filters','levels'=>['Q']],
        ['item'=>'Verify Reflow Performance and Temperature Uniformity After Maintenance','levels'=>['Q']],
        ['item'=>'Perform a Comprehensive Inspection of Electrical, Mechanical and Software Components','levels'=>['H']],
        ['item'=>'Perform a Major Overhaul, Including Replacement of Critical Components and Refurbishment of Worn Parts','levels'=>['A']],
    ];
}

function uvlRad2000ChecklistItems(): array {
    return [
        ['item'=>'External Machine Body Cleaning','levels'=>['Q']],
        ['item'=>'Wafer Ring Mounting Cleaning','levels'=>['Q']],
        ['item'=>'UV Lamp Calibration and Lamp Replacement After 1,000 Hours','levels'=>['Q']],
        ['item'=>'Safety Lock Sensor Inspection','levels'=>['Q']],
        ['item'=>'ESD Ground-Wire Inspection','levels'=>['Q']],
    ];
}

function wmnNelMsa840ChecklistItems(): array {
    return [
        ['item'=>'Roller','levels'=>['W']],
        ['item'=>'Table Flatness','levels'=>['W']],
        ['item'=>'C-Blade Status','levels'=>['M']],
        ['item'=>'C-Blade Position','levels'=>['M']],
        ['item'=>'Blade Spring','levels'=>['M']],
        ['item'=>'Wafer Ring Vacuum Pad','levels'=>['M']],
        ['item'=>'Tape Lock Function','levels'=>['M']],
        ['item'=>'Cleaning Ionizer System','levels'=>['M']],
        ['item'=>'Table Vacuum Pad','levels'=>['H']],
        ['item'=>'Rubber Belt','levels'=>['H']],
        ['item'=>'Air Pipe','levels'=>['H']],
        ['item'=>'Table Heating Function','levels'=>['H']],
        ['item'=>'Check for Loose Screws','levels'=>['H']],
        ['item'=>'Change C-Blade','levels'=>['H']],
    ];
}

function wrdDwr1722ChecklistItems(): array {
    return [
        ['item'=>'Air Supply Pressure — Confirm It Is Within the Specified Range','levels'=>['M']],
        ['item'=>'Water Supply — Check Water Quality and Confirm There Is No Black Residue','levels'=>['M']],
        ['item'=>'Water Supply — Check Piping for Water Leakage','levels'=>['M']],
        ['item'=>'Cutting Water — Check Specific Resistance After Processing (Greater Than 12 Mohm-cm)','levels'=>['M']],
        ['item'=>'Cutting Water — Check Temperature (20-25 °C)','levels'=>['M']],
        ['item'=>'Spindle Cooling Water — Check Temperature (20-25 °C)','levels'=>['M']],
        ['item'=>'CC Filter Check','levels'=>['Q']],
        ['item'=>'Micro Filter Check','levels'=>['Q']],
        ['item'=>'RO Filter Check','levels'=>['Q']],
        ['item'=>'Tank Cleaning','levels'=>['Q']],
        ['item'=>'Air Clean Unit Cleaning','levels'=>['A']],
        ['item'=>'ESD — Check Machine Grounding Connections','levels'=>['M']],
    ];
}

function wsdDfd6362ChecklistItems(): array {
    return [
        ['item'=>'Clean the X-Axis Bellows','levels'=>['W']],
        ['item'=>'Clean the Cutting and Spinner Table','levels'=>['W']],
        ['item'=>'Check Revolving Arm Status','levels'=>['W']],
        ['item'=>'Check Spinner-Table Original Position and Up/Down Movement','levels'=>['W']],
        ['item'=>'Check Spindle Rotation','levels'=>['W']],
        ['item'=>'Check Frame-Clamp Condition','levels'=>['W']],
        ['item'=>'Check Water-Leakage System','levels'=>['M']],
        ['item'=>'Clean and Check Water-Nozzle Status','levels'=>['M']],
        ['item'=>'Measure Circular Wafer-Table Planarity','levels'=>['M']],
        ['item'=>'Check C/T Vacuum (kPa) and Confirm Status Is Below -60','levels'=>['M']],
        ['item'=>'Check Flange Runout','levels'=>['M']],
        ['item'=>'Check and Clean Water Drainage','levels'=>['M']],
        ['item'=>'Check Focus','levels'=>['M']],
        ['item'=>'Check Spindle Cooling-Water Path','levels'=>['Q']],
        ['item'=>'Check Complete I/O Chains','levels'=>['Q']],
        ['item'=>'Sweep and Clean Machine Inner Parts','levels'=>['Q']],
        ['item'=>'Clean Oil Pan','levels'=>['H']],
        ['item'=>'Check Flange-Surface Thrust-Direction Runout','levels'=>['H']],
        ['item'=>'Clean X-Axis Guide Area','levels'=>['H']],
        ['item'=>'Check Camera Magnification Offset','levels'=>['H']],
        ['item'=>'Check EMO Operation','levels'=>['H']],
        ['item'=>'Grease Y/Z and Elevator-Axis Guides and Ball Screws','levels'=>['H']],
        ['item'=>'Check Flow-Rate Sensor Height','levels'=>['H']],
        ['item'=>'Clean Water Flowmeter','levels'=>['H']],
        ['item'=>'Clean Vacuum Ejector','levels'=>['A']],
        ['item'=>'Check Spindle Carbon Brush','levels'=>['A']],
        ['item'=>'Check Halogen Lamp','levels'=>['A']],
    ];
}

function rcdDfd6240ChecklistItems(): array {
    return [
        ['item'=>'Clean Chuck-Table Surface and Metal Base','levels'=>['Q']],
        ['item'=>'Clean Taiko Chuck Table','levels'=>['Q']],
        ['item'=>'Clean Taiko UV Unit','levels'=>['Q']],
        ['item'=>'Clean Dust Box','levels'=>['Q']],
        ['item'=>'Clean Water Bellows','levels'=>['Q']],
        ['item'=>'Clean Push-Pull Arm Assembly','levels'=>['Q']],
        ['item'=>'Clean Rotary Arm Assembly','levels'=>['Q']],
        ['item'=>'Clean OPO Roller Assembly','levels'=>['Q']],
        ['item'=>'Clean Linear Arm','levels'=>['Q']],
        ['item'=>'Clean Spindle','levels'=>['Q']],
        ['item'=>'Clean Drain-Water Tank and Overflow Sensors','levels'=>['Q']],
        ['item'=>'Check Non-Contact Set-Up Sensor; Bar Graph Must Be Green (100% ±2%, 4.9-5.1 V)','levels'=>['Q']],
        ['item'=>'Check BBD Sensor at 10% ±5% Sensitivity Before and After PM for Z1 and Z2','levels'=>['Q']],
        ['item'=>'Clean Table Base and Water Clamper','levels'=>['Q']],
        ['item'=>'Check Harnessing of Y-Axis and Spindle Assembly','levels'=>['Q']],
        ['item'=>'Clean Spinner Table and Chamber','levels'=>['Q']],
        ['item'=>'Check Water and Air Curtain','levels'=>['Q']],
        ['item'=>'Clean Spray Nozzle','levels'=>['Q']],
        ['item'=>'Check Chuck-Table Air Blow','levels'=>['Q']],
        ['item'=>'Perform Chuck-Table Bubble Test','levels'=>['Q']],
        ['item'=>'Check Spindle Carbon Brush (≤33 Ohms Between Spindle Shaft and Machine Body)','levels'=>['Q']],
        ['item'=>'Check Gauges and Meters','levels'=>['Q']],
        ['item'=>'Check Spindle Coolant Flow-Rate Sensor','levels'=>['Q']],
        ['item'=>'Record High-Pressure Gauge Reading','levels'=>['Q']],
        ['item'=>'Check Lower-Arm Vacuum Sensor','levels'=>['Q']],
        ['item'=>'Check Chuck-Table Vacuum Sensor','levels'=>['Q']],
        ['item'=>'Check Cutting-Water Flow Sensor','levels'=>['Q']],
        ['item'=>'Check Upper-Arm Vacuum Sensor','levels'=>['Q']],
        ['item'=>'Check Spinner Vacuum Sensor','levels'=>['Q']],
        ['item'=>'Check Main Air-Pressure Sensor','levels'=>['Q']],
        ['item'=>'Check Tower Light','levels'=>['Q']],
        ['item'=>'Check UV Sensor','levels'=>['Q']],
        ['item'=>'Check UV Lamp','levels'=>['Q']],
        ['item'=>'Lubricate X-Axis Linear-Guide Bearing and Lead Screw','levels'=>['H']],
        ['item'=>'Lubricate Y-Axis Linear-Guide Bearing and Lead Screw','levels'=>['H']],
        ['item'=>'Lubricate Z-Axis Linear-Guide Bearing and Lead Screw','levels'=>['H']],
        ['item'=>'Lubricate Microscope-Axis Ball Bearing and Lead Screw','levels'=>['H']],
        ['item'=>'Lubricate Elevator Axis','levels'=>['H']],
        ['item'=>'Lubricate Front and Rear Frame-Centering Axis Guides','levels'=>['H']],
        ['item'=>'ESD — Check Machine Grounding Connections','levels'=>['Q']],
    ];
}

function checklistPrefixModelMap(): array {
    return [
        'DBA'=>'AD838','FCG'=>'SC700','FCA'=>'SC700','PLV'=>'VSP-88D PRO1 NEO',
        'ROA'=>'R0832','UVL'=>'RAD-2000m/12','WMN'=>'NEL MSA840VIII',
        'WRD'=>'DWR1722','WSD'=>'DFD6362','RCD'=>'DFD6240',
    ];
}

function initialChecklistTemplates(): array {
    $items=[]; foreach (plvChecklistItems() as $number=>$row) $items[]=['id'=>$number,'check_item'=>$row['item'],'frequencies'=>$row['levels']];
    return [['id'=>1,'model_number'=>'VSP-88D PRO1 NEO','items'=>$items]];
}

function normalizedMachineId(string $machineId): string { return strtoupper((string)preg_replace('/\s+/','',trim($machineId))); }
function modelNumberForMachineId(array $data, string $machineId): string {
    $wanted=normalizedMachineId($machineId);
    foreach (($data['aux_information']??[]) as $row) { $values=normalizeAuxInformationRow($row['values']??[]); if (normalizedMachineId($values[0])===$wanted) return trim($values[7]); }
    return '';
}
function findChecklistTemplateByModel(array $data, string $modelNumber): ?array {
    foreach (($data['checklist_templates']??[]) as $template) if (strcasecmp(trim((string)($template['model_number']??'')),trim($modelNumber))===0) return $template;
    return null;
}
function findChecklistTemplate(array $data, string $machineId): ?array {
    $normalized=normalizedMachineId($machineId);
    foreach (checklistPrefixModelMap() as $prefix=>$templateModel) {
        if (str_starts_with($normalized,$prefix.'-')) return findChecklistTemplateByModel($data,$templateModel);
    }
    $modelNumber=modelNumberForMachineId($data,$machineId);
    return $modelNumber==='' ? null : findChecklistTemplateByModel($data,$modelNumber);
}

function advanceToPreviousScheduleDay(DateTimeImmutable $date): DateTimeImmutable {
    $weekday=(int)$date->format('N');
    // Saturday is an allowed PM day. Only Sunday advances to Saturday.
    if ($weekday===7) return $date->modify('-1 day');
    return $date;
}

function dueOccurrenceForWorkWeek(string $anchorDate, string $frequency, int $year, int $workWeek): ?string {
    if ($anchorDate==='' || !in_array($frequency,['W','M','Q','H','A'],true)) return null;
    $weekStart=(new DateTimeImmutable())->setISODate($year,$workWeek)->setTime(0,0);
    $weekEnd=$weekStart->modify('+6 days')->setTime(23,59,59);
    $occurrence=(new DateTimeImmutable($anchorDate))->setTime(0,0);
    if ($occurrence>$weekEnd) return null;
    $step=['W'=>'+7 days','M'=>'+4 weeks','Q'=>'+12 weeks','H'=>'+26 weeks','A'=>'+52 weeks'][$frequency];
    while ($occurrence<$weekStart) $occurrence=$occurrence->modify($step);
    $workingDate=advanceToPreviousScheduleDay($occurrence);
    return $workingDate>=$weekStart && $workingDate<=$weekEnd ? $workingDate->format('Y-m-d') : null;
}

function scheduleFromEnrollmentDate(string $enrollmentDate, array $frequencies, int $year): array {
    $schedule=array_fill(0,52,''); $dueDates=array_fill(0,52,'');
    // Fixed intervals prevent Monthly PM from extending beyond 28 days.
    $intervals=['W'=>1,'M'=>4,'Q'=>12,'H'=>26,'A'=>52];
    $firstOffsets=['W'=>0,'M'=>4,'Q'=>12,'H'=>26,'A'=>51];
    $priority=['W'=>1,'M'=>2,'Q'=>3,'H'=>4,'A'=>5];
    $start=(new DateTimeImmutable($enrollmentDate))->setTime(0,0);
    foreach ($frequencies as $frequency) {
        if (!isset($intervals[$frequency])) continue;
        for ($offset=$firstOffsets[$frequency];$offset<52;$offset+=$intervals[$frequency]) {
            // Keep the recurrence anchored to its original cycle. Saturday
            // remains valid; Sunday advances to Saturday without causing drift.
            $cycleDate=$start->modify('+'.$offset.' weeks');
            $dueDate=advanceToPreviousScheduleDay($cycleDate); $isoYear=(int)$dueDate->format('o'); $week=(int)$dueDate->format('W');
            if ($isoYear!==$year || $week<1 || $week>52) continue;
            $current=$schedule[$week-1];
            if ($current==='' || $priority[$frequency]>$priority[$current]) { $schedule[$week-1]=$frequency; $dueDates[$week-1]=$dueDate->format('Y-m-d'); }
        }
    }
    return ['schedule'=>$schedule,'due_dates'=>$dueDates];
}

function sendDueEmailNotifications(array &$data, bool $force=false): array {
    $year=(int)$data['year']; $workWeek=(int)date('W'); $today=date('Y-m-d'); $completed=[]; $due=[];
    foreach (($data['logs']??[]) as $log) if ((int)($log['year']??0)===$year) $completed[$log['equipment_id'].'-'.$log['week']]=true;
    foreach ($data['equipment'] as $eq) {
        $frequency=(string)($eq['schedule'][$workWeek-1]??'');
        $dueDate=(string)($eq['due_dates'][$workWeek-1]??'');
        if (($eq['schedule_mode']??'')!=='enrollment_combined' && ($frequency==='' || $dueDate==='')) {
            $frequency=(string)($eq['planned_frequency']??'');
            $dueDate=dueOccurrenceForWorkWeek((string)($eq['planned_due_date']??''),$frequency,$year,$workWeek)??'';
        }
        if ($dueDate!=='' && $frequency!=='' && !isset($completed[$eq['id'].'-'.$workWeek])) {
            $daysRemaining=(int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable($dueDate))->format('%r%a');
            if ($daysRemaining<=2) { $alarmLabel=$daysRemaining<0?'OVERDUE':($daysRemaining===0?'DUE TODAY':'DUE IN '.$daysRemaining.' DAY'.($daysRemaining===1?'':'S')); $due[]=$alarmLabel.' | '.$eq['code'].' | '.$eq['name'].' | '.$dueDate.' | '.frequencyName($frequency); }
        }
    }
    if (!$due) return ['sent'=>0,'failed'=>0,'due'=>0];
    $subject='PM Advance Alarm / Due Notification - WW'.str_pad((string)$workWeek,2,'0',STR_PAD_LEFT);
    $message="Preventive Maintenance alarms due within 2 days or overdue for WW".str_pad((string)$workWeek,2,'0',STR_PAD_LEFT)."\n\n".implode("\n",$due)."\n\nGenerated: ".date('d/m/Y H:i:s').' PHT';
    $sent=0; $failed=0;
    if (!isset($data['email_delivery']) || !is_array($data['email_delivery'])) $data['email_delivery']=[];
    $smtpHost=trim((string)(getenv('SMTP_HOST') ?: 'smtp-relay.brevo.com'));
    $smtpPort=(int)(getenv('SMTP_PORT') ?: 587);
    $smtpUsername=trim((string)getenv('SMTP_USERNAME'));
    $smtpPassword=(string)getenv('SMTP_PASSWORD');
    $smtpEncryption=strtolower(trim((string)(getenv('SMTP_ENCRYPTION') ?: 'tls')));
    $fromEmail=trim((string)getenv('SMTP_FROM_EMAIL'));
    $fromName=trim((string)(getenv('SMTP_FROM_NAME') ?: 'PM Scheduling System'));
    $smtpConfigured=class_exists(\PHPMailer\PHPMailer\PHPMailer::class)
        && $smtpHost!=='' && $smtpPort>0 && $smtpUsername!=='' && $smtpPassword!==''
        && filter_var($fromEmail,FILTER_VALIDATE_EMAIL) && in_array($smtpEncryption,['tls','ssl'],true);
    foreach ($data['users'] as $user) {
        $email=(string)($user['email']??''); if (($user['status']??'')!=='approved' || empty($user['email_notifications']) || !filter_var($email,FILTER_VALIDATE_EMAIL)) continue;
        $deliveryKey=$today.'|'.strtolower($email);
        if (!$force && isset($data['email_delivery'][$deliveryKey])) continue;
        $ok=false;
        if (!$smtpConfigured) {
            error_log('PM email not sent: Brevo SMTP is not fully configured. Check SMTP_* environment variables and PHPMailer installation.');
        } else {
            try {
                $mailer=new \PHPMailer\PHPMailer\PHPMailer(true);
                $mailer->isSMTP();
                $mailer->Host=$smtpHost;
                $mailer->SMTPAuth=true;
                $mailer->Username=$smtpUsername;
                $mailer->Password=$smtpPassword;
                $mailer->Port=$smtpPort;
                $mailer->SMTPSecure=$smtpEncryption=== 'ssl'
                    ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mailer->CharSet='UTF-8';
                $mailer->setFrom($fromEmail,$fromName);
                $mailer->addAddress($email);
                $mailer->Subject=$subject;
                $mailer->Body=$message;
                $mailer->isHTML(false);
                $mailer->send();
                $ok=true;
            } catch (\Throwable $mailError) {
                error_log('PM Brevo SMTP delivery failed for '. $email . ': ' . $mailError->getMessage());
            }
        }
        $ok?$sent++:$failed++;
        $data['email_delivery'][$deliveryKey]=['timestamp'=>date('c'),'status'=>$ok?'sent':'failed','due_count'=>count($due)];
    }
    return ['sent'=>$sent,'failed'=>$failed,'due'=>count($due)];
}

/** Send a standalone SMTP test message to the currently logged-in administrator. */
function sendSmtpTestEmail(string $recipient, string $recipientName='Administrator'): array {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return ['ok'=>false, 'message'=>'Add a valid email address to your Admin profile first.'];
    }
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        error_log('PM email test failed: PHPMailer autoloader/class is unavailable.');
        return ['ok'=>false, 'message'=>'PHPMailer is not installed. Check the Composer install step in the Render build logs.'];
    }

    $host=trim((string)(getenv('SMTP_HOST') ?: 'smtp-relay.brevo.com'));
    $port=(int)(getenv('SMTP_PORT') ?: 587);
    $username=trim((string)getenv('SMTP_USERNAME'));
    $password=(string)getenv('SMTP_PASSWORD');
    $encryption=strtolower(trim((string)(getenv('SMTP_ENCRYPTION') ?: 'tls')));
    $fromEmail=trim((string)getenv('SMTP_FROM_EMAIL'));
    $fromName=trim((string)(getenv('SMTP_FROM_NAME') ?: 'PM Scheduling System'));

    $missing=[];
    if ($host==='') $missing[]='SMTP_HOST';
    if ($port<1) $missing[]='SMTP_PORT';
    if ($username==='') $missing[]='SMTP_USERNAME';
    if ($password==='') $missing[]='SMTP_PASSWORD';
    if (!filter_var($fromEmail,FILTER_VALIDATE_EMAIL)) $missing[]='SMTP_FROM_EMAIL (must be a valid verified sender address)';
    if (!in_array($encryption,['tls','ssl'],true)) $missing[]='SMTP_ENCRYPTION (use tls or ssl)';
    if ($missing) return ['ok'=>false, 'message'=>'Missing or invalid Render environment setting(s): '.implode(', ',$missing).'.'];

    try {
        $mailer=new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host=$host;
        $mailer->SMTPAuth=true;
        $mailer->Username=$username;
        $mailer->Password=$password;
        $mailer->Port=$port;
        $mailer->SMTPSecure=$encryption==='ssl'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Timeout=20;
        $mailer->CharSet='UTF-8';
        $mailer->setFrom($fromEmail,$fromName);
        $mailer->addAddress($recipient,$recipientName);
        $mailer->isHTML(false);
        $mailer->Subject='PM Scheduling System - SMTP Test';
        $mailer->Body="This is a test email from the PM Scheduling System.\n\nIf you received this message, the SMTP configuration is working.\n\nSent: ".date('d/m/Y H:i:s').' PHT';
        $mailer->send();
        return ['ok'=>true, 'message'=>'Test email accepted by the SMTP server for '.$recipient.'. Check the inbox and spam folder.'];
    } catch (\Throwable $mailError) {
        // PHPMailer error text is useful for diagnostics; never log the SMTP password.
        error_log('PM SMTP test failed: '.$mailError->getMessage());
        return ['ok'=>false, 'message'=>'SMTP test failed: '.$mailError->getMessage()];
    }
}

function auxInformationHeaders(): array {
    return ['Machine ID','Machine Name','Associated Process','Serial No','Category','Station','Location','Model Number','Manufacturing Date'];
}

function normalizeAuxInformationRow(array $row): array {
    if (count($row)>=10) array_splice($row,2,1);
    return array_pad(array_slice(array_map(fn($value)=>trim((string)$value),$row),0,9),9,'');
}

function auxInformationSource(): array {
    return [
        ['DBB-002','BESI SWITZERLAND AG','Die Attach','Epoxy Die bonder','9501821008513','Die Attach','FOL','UNIT 1','ESEC2100hsi','2025-12-01'],
        ['DBB-001','BESI SWITZERLAND AG','Die Attach','Epoxy Die bonder','9501821008512','Die Attach','FOL','UNIT 1','ESEC2100hsi','2025-12-01'],
        ['3OQ-001','QEZ MECHATRONIC SDN BHD','Automatic Optical Inspection','3rd Optical Inspection','25289PWB2000','Wirebond','FOL','UNIT 1','PWB2000','2025-07-01'],
        ['','OLYMPUS','Low Power Scope','Inspection scope','5C00872','Low Power Scope','QA','UNIT 1','SZ2-STU1','not avail'],
        ['','OLYMPUS','Low Power Scope','Inspection scope','5C00871','Low Power Scope','FOL','UNIT 1','SZ2-STU1','not avail'],
        ['','OLYMPUS','Measuring Scope','Measuring Scope','5E40800','Measuring Scope','QA','UNIT 1','STM7-LFA','not avail'],
        ['','MOTIC','Measuring Scope','Measuring Scope','4250002476','Measuring Scope','FOL','UNIT 1','MOTIC 5P EASY ZOOM','not avail'],
        ['WBAK-001','KULICKE & SOFFA PTE LTD','Wirebond','Aliminum Wedge bonder','PP225040009-2374606','Wirebond','FOL','UNIT 1','POWER-C PLUS','2025-05-23'],
        ['WBAK-002','KULICKE & SOFFA PTE LTD','Wirebond','Aliminum Wedge bonder','PP225040008-2374596','Wirebond','FOL','UNIT 1','POWER-C PLUS','2025-06-14'],
        ['PLV-001','VISION SEMICON CO.,LTD','Plasma','Direct Plasma Cleaner','VSPD250304','Plasma','FOL','UNIT 1','VSP-88D PRO1 NEO','2025-06-10'],
        ['PLV-002','VISION SEMICON CO.,LTD','Plasma','Direct Plasma Cleaner','VSPD250303','Plasma','FOL','UNIT 1','VSP-88D PRO1 NEO','2025-05-11'],
        ['','OLYMPUS','High Power Scope','Inspection scope','4G46101','High Power Scope','FOL','UNIT 1','MX63L-F','not avail'],
        ['WBK-001','KULICKE & SOFFA PTE LTD','Wirebond','Copper Wirebonder','PN111572-2378461','Wirebond','FOL','UNIT 1','POWERNEXX','2025-06-12'],
        ['WBK-002','KULICKE & SOFFA PTE LTD','Wirebond','Copper Wirebonder','PN11475-2378459','Wirebond','FOL','UNIT 1','POWERNEXX','2025-05-13'],
        ['WBJ-001','KAIJO CORPORATION','Wirebond','Copper Wirebonder','F19V0123','Wirebond','FOL','UNIT 1','FB-e20S','not avail'],
        ['','NORDSON TEST & INSPECTION','Shear and Pull Tester','Shear and Pull Tester','20009925','Shear and Pull Tester','FOL','UNIT 1','4000PXY(STELLAR) MAINFRAME  INC  XY','2025-05-01'],
        ['','NORDSON TEST & INSPECTION','Shear and Pull Tester','Shear and Pull Tester','20009825','Shear and Pull Tester','FOL','UNIT 1','4000PXY(STELLAR) MAINFRAME  INC  XY','2025-05-01'],
        ['XRN-001','NORDSON TEST & INSPECTION','X-ray','X ray Inspection','XQP228925','X-ray','FOL','UNIT 1','QUADRA\'S 5 PRO','2025-05-01'],
        ['OVB-001','THERMAL PRODUCT SOLUTION','Oven Curing','Epoxy Oven Curing','175032-02','Oven Curing','FOL','UNIT 1','DCI-336-KY-F4T','not avail'],
        ['FCG-001','GUANDONG ADVANCE SPRAY CLEANING TECHNOLOGY CO.,LTD','Flux cleaner','Solder Paste Flux Cleaner','250627006','Flux cleaner','FOL','UNIT 1','SC700','2025-06-30'],
        ['','NORDSON TEST & INSPECTION','Shear and Pull Tester','Shear and Pull Tester','20009725','Shear and Pull Tester','FOL','UNIT 1','4000PXY(STELLAR) MAINFRAME  INC  XY','2025-05-01'],
        ['','OLYMPUS','Low Power Scope','Inspection scope','4L01074','Low Power Scope','FOL','UNIT 1','SZ2-STU1','not avail'],
        ['','OLYMPUS','Low Power Scope','Inspection scope','5C00863','Low Power Scope','FOL','UNIT 1','SZ2-STU1','not avail'],
        ['DBA-002','ASM PACIFIC TECHNOLOGY','Die Attach','Epoxy Die bonder','AD838-02847-0976','Die Attach','FOL','UNIT 1','AD838','2011-05-01'],
        ['DBA-001','ASM PACIFIC TECHNOLOGY','Die Attach','Epoxy Die bonder','AD838-02849-0978','Die Attach','FOL','UNIT 1','AD838','2011-05-01'],
        ['','OLYMPUS','High Power Scope','Inspection scope','4G46099','High Power Scope','FOL','UNIT 1','MX63L-F','not avail'],
        ['DBA-005','ASM PACIFIC TECHNOLOGY','Die Attach','Solder Paste Die Bonder','AD838-08313-3858','Die Attach','FOL','UNIT 1','AD838','2020-08-01'],
        ['DBA-004','ASM PACIFIC TECHNOLOGY','Die Attach','Solder Paste Die Bonder','R0832-08408-3873','Die Attach','FOL','UNIT 1','AD838','2020-09-01'],
        ['DBA-003','ASM PACIFIC TECHNOLOGY','Die Attach','Solder Paste Die Bonder','AD838-08312-3857','Die Attach','FOL','UNIT 1','AD838','2020-08-01'],
        ['n/a','ASM PACIFIC TECHNOLOGY','VOC Filter','Solder Paste VOC Bonder','FLR-0001-0308','VOC Filter','FOL','UNIT 1','R0832','not avail'],
        ['ROA-001','ASM PACIFIC TECHNOLOGY','Oven Curing','Solder Paste VOC Bonder','R0832-0804-0055','Inline Reflow Oven','FOL','UNIT 1','R0832','2021-02-01'],
        ['2DG-001','GENESEM INC.','2D Marker','2D Marker','A4517','2D Marker','FOL','UNIT 1','GBMS-1000L','2025-06-05'],
        ['','OLYMPUS','High Power Scope','Inspection scope','4G46100','High Power Scope','CDP','UNIT 1','MX63L-F','not avail'],
        ['UVL-001','LINTEC CORPORATION','UV Cure','UV tape Curing','D11S001641','UV Cure','CDP','UNIT 1','RAD-2000m/12','2011-04-01'],
        ['WRD-001','DISCO CORPORATION','DI water recycling Unit','Water Recyle','NR3448','DI water recycling Unit','CDP','UNIT 1','DWR1722','2025-01-01'],
        ['RCD-001','DISCO CORPORATION','Wafer Ring Cut','Ring Cut Remover','KHA091','Wafer Ring Cut','CDP','UNIT 1','DFD6240','2025-06-01'],
        ['WSD-001','DISCO CORPORATION','Wafer sawing','Wafer Saw','NLD065','Wafer sawing','CDP','UNIT 1','DFD6362','2024-09-01'],
        ['WMN-001','NITTO DENKO CORPORATION','Wafer Mounter','Wafer Mounter','B048125','Wafer Mounter','CDP','UNIT 1','NEL MSA840VIII','2025-06-05'],
        ['DLT-001','TOTA TECHNOLOGY','DBC Loader','DBC Loader','TA2530-004','DBC Loader','FOL','UNIT 1','DMM','not avail'],
        ['','','Input loader','Input loader','TA2530-001','Input loader','FOL','UNIT 1','SLD300','not avail'],
        ['','','Conveyor','leadframe transfer','TA2527-5-031','Conveyor','FOL','UNIT 1','CV-L1','not avail'],
        ['','','Conveyor','leadframe transfer','TA2527-5-032','Conveyor','FOL','UNIT 1','CV-L1','not avail'],
        ['','','Output loader','Output Loader','TA2530-002','Output Loader','FOL','UNIT 1','SLD300','not avail'],
        ['LDT-001','TOTA TECHNOLOGY','Leadframe detach','Leadframe detach','TA2519-003','Leadframe detach','FOL','UNIT 1','PM7DM-L2','2026-01-12'],
        ['IDU-001','UNIVERSAL SYSTEM','IGBT Die Attach','IGBT Die Attach','53183301','IGBT Die Attach','FOL','UNIT 1','HSWF-REAR','2026-01-15'],
        ['SPA-001','ASM PACIFIC TECHNOLOGY','Screen print','Screen print','867319','Screen print','FOL','UNIT 1','NeoHorizon 03IX','not avail'],
        ['VRH-001','HELLER INDUSTRIES INC','Vacuum Reflow','Vacuum Reflow','0126Z5F-DCNJN-862001-03','Vacuum Reflow','FOL','UNIT 1','1912MK5-VR','not avail'],
    ];
}
function initialData(): array {
    $equipment = []; $dueDates=plannedDueDates(); $frequencies=plannedFrequencies();
    foreach (sourceRows() as $i => $row) {
        $equipment[] = ['id'=>$i+1, 'name'=>$row[0], 'code'=>$row[1], 'schedule'=>normalizePattern($row[2]), 'due_dates'=>array_fill(0,52,''), 'planned_due_date'=>$dueDates[$row[1]]??'', 'planned_frequency'=>$frequencies[$row[1]]??''];
    }
    $auxRecords=[]; foreach (auxInformationSource() as $i=>$row) $auxRecords[]=['id'=>$i+1,'values'=>normalizeAuxInformationRow($row)];
    return ['year'=>APP_YEAR, 'source_revision'=>SOURCE_REVISION, 'next_id'=>count($equipment)+1, 'equipment'=>$equipment, 'logs'=>[], 'aux_headers'=>auxInformationHeaders(), 'next_aux_id'=>count($auxRecords)+1, 'aux_information'=>$auxRecords];
}

function equipmentKey(string $name): string {
    return strtolower((string)preg_replace('/[^a-z0-9]+/i','',$name));
}

function saveData(array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents(DATA_FILE, $json, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write pm_schedule_data.json. Allow write permission for this folder.');
    }
}

function loadData(): array {
    if (!file_exists(DATA_FILE)) saveData(initialData());
    $data = json_decode((string)file_get_contents(DATA_FILE), true);
    if (!is_array($data)) { $data = initialData(); saveData($data); }
    if (($data['source_revision'] ?? '') !== SOURCE_REVISION) {
        $old=$data; $data=initialData(); $oldByName=[]; $idMap=[];
        foreach (($old['equipment'] ?? []) as $oldEq) $oldByName[equipmentKey((string)($oldEq['name']??''))]=$oldEq;
        foreach ($data['equipment'] as &$newEq) {
            $key=equipmentKey($newEq['name']);
            if (isset($oldByName[$key])) {
                $matched=$oldByName[$key];
                $newEq['due_dates']=array_pad(array_slice($matched['due_dates']??[],0,52),52,'');
                $idMap[(int)($matched['id']??0)]=(int)$newEq['id'];
            }
        }
        unset($newEq); $data['logs']=[];
        foreach (($old['logs']??[]) as $log) if (isset($idMap[(int)($log['equipment_id']??0)])) {
            $log['equipment_id']=$idMap[(int)$log['equipment_id']]; $data['logs'][]=$log;
        }
        saveData($data);
    }
    return $data;
}

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function weekDate(int $year, int $week): string {
    return (new DateTimeImmutable())->setISODate($year, $week)->format('M d');
}
function weekDateISO(int $year, int $week): string {
    return (new DateTimeImmutable())->setISODate($year, $week)->format('Y-m-d');
}
function frequencyName(string $f): string {
    return ['W'=>'Weekly','M'=>'Monthly','Q'=>'Quarterly','H'=>'Semi-annual','A'=>'Annual'][$f] ?? 'Not scheduled';
}
function frequencyCode(string $f): string { return $f; }
function setFlash(string $message): void { $_SESSION['flash']=$message; }
function findUser(array $data, int $userId): ?array {
    foreach (($data['users']??[]) as $user) if ((int)$user['id']===$userId) return $user;
    return null;
}
function auditAction(array &$data, ?array $user, string $action, string $details): void {
    $data['audit_logs'][]=['timestamp'=>date('c'),'user_id'=>$user['id']??null,'employee_no'=>$user['employee_no']??'SYSTEM','name'=>$user['name']??'System','action'=>$action,'details'=>$details];
}
function saveEnrollmentPhoto(string $field='photo'): array {
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return ['', ''];
    if (($_FILES[$field]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) return ['', 'The photo could not be uploaded. Please try again or add it later from My Profile.'];
    $file=$_FILES[$field];
    if (($file['size']??0)>2*1024*1024) return ['', 'Photo must not exceed 2 MB.'];
    if (!is_uploaded_file((string)$file['tmp_name'])) return ['', 'Invalid photo upload.'];
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($extensions[$mime]) || @getimagesize((string)$file['tmp_name'])===false) return ['', 'Upload a valid JPG, PNG, or WebP photo.'];
    if (!is_dir(USER_PHOTO_DIR) && !mkdir(USER_PHOTO_DIR,0755,true)) return ['', 'The photo folder could not be created.'];
    $filename=bin2hex(random_bytes(16)).'.'.$extensions[$mime]; $target=USER_PHOTO_DIR.'/'.$filename;
    if (!move_uploaded_file((string)$file['tmp_name'],$target)) return ['', 'The personnel photo could not be saved.'];
    return ['pm_user_photos/'.$filename, ''];
}

$data = loadData();
// Apply the latest approved Excel calendar without replacing users, logs,
// approvals, photos, checklists, auxiliary information or equipment IDs.
if (($data['schedule_reference_revision']??'')!==SCHEDULE_REFERENCE_REVISION) {
    $scheduleByCode=[]; $scheduleByName=[];
    foreach (sourceRows() as $referenceRow) {
        $referenceSchedule=normalizePattern((string)$referenceRow[2]);
        $scheduleByCode[normalizedMachineId((string)$referenceRow[1])]=$referenceSchedule;
        $scheduleByName[equipmentKey((string)$referenceRow[0])]=$referenceSchedule;
    }
    foreach ($data['equipment'] as &$scheduledEquipment) {
        $codeKey=normalizedMachineId((string)($scheduledEquipment['code']??''));
        $nameKey=equipmentKey((string)($scheduledEquipment['name']??''));
        $referenceSchedule=$scheduleByCode[$codeKey]??$scheduleByName[$nameKey]??null;
        if (!is_array($referenceSchedule)) continue;
        $scheduledEquipment['schedule']=$referenceSchedule;
        $scheduledEquipment['due_dates']=array_pad(array_slice($scheduledEquipment['due_dates']??[],0,52),52,'');
        foreach ($referenceSchedule as $referenceIndex=>$referenceFrequency) {
            if ($referenceFrequency==='') continue;
            if (($scheduledEquipment['due_dates'][$referenceIndex]??'')==='') {
                $scheduledEquipment['due_dates'][$referenceIndex]=(new DateTimeImmutable())->setISODate((int)$data['year'],$referenceIndex+1)->modify('+5 days')->format('Y-m-d');
            } else {
                $scheduledEquipment['due_dates'][$referenceIndex]=advanceToPreviousScheduleDay(new DateTimeImmutable((string)$scheduledEquipment['due_dates'][$referenceIndex]))->format('Y-m-d');
            }
        }
        $scheduledEquipment['schedule_mode']='reference_calendar';
    }
    unset($scheduledEquipment);
    $data['schedule_reference_revision']=SCHEDULE_REFERENCE_REVISION;
    saveData($data);
}
// Compatibility for data files created by earlier versions.
$fixedDueDates=plannedDueDates(); $fixedFrequencies=plannedFrequencies(); $scheduleRulesUpdated=false;
foreach ($data['equipment'] as &$existingEquipment) {
    $equipmentCode=(string)($existingEquipment['code']??'');
    if (empty($existingEquipment['planned_due_date']) && isset($fixedDueDates[$equipmentCode])) $existingEquipment['planned_due_date']=$fixedDueDates[$equipmentCode];
    if (empty($existingEquipment['planned_frequency']) && isset($fixedFrequencies[$equipmentCode])) $existingEquipment['planned_frequency']=$fixedFrequencies[$equipmentCode];
    if (!isset($existingEquipment['planned_frequencies']) || !is_array($existingEquipment['planned_frequencies'])) $existingEquipment['planned_frequencies']=!empty($existingEquipment['planned_frequency'])?[(string)$existingEquipment['planned_frequency']]:[];
    if (!isset($existingEquipment['due_dates']) || !is_array($existingEquipment['due_dates'])) {
        $existingEquipment['due_dates']=array_fill(0,52,'');
    } else {
        $existingEquipment['due_dates']=array_pad(array_slice($existingEquipment['due_dates'],0,52),52,'');
    }
    if (($existingEquipment['schedule_mode']??'')==='enrollment_combined' && (int)($existingEquipment['schedule_rule_version']??0)<2) {
        $startDate=(string)($existingEquipment['enrolled_on']??$existingEquipment['planned_due_date']??'');
        $frequencies=array_values(array_intersect(['W','M','Q','H','A'],$existingEquipment['planned_frequencies']??[]));
        if ($startDate!=='' && $frequencies) {
            $regenerated=scheduleFromEnrollmentDate($startDate,$frequencies,(int)$data['year']);
            $existingEquipment['schedule']=$regenerated['schedule']; $existingEquipment['due_dates']=$regenerated['due_dates'];
            $existingEquipment['schedule_rule_version']=2; $scheduleRulesUpdated=true;
        }
    }
}
unset($existingEquipment);
if ($scheduleRulesUpdated) saveData($data);

// RCD-001 follows only the approved Quarterly and Half-yearly calendar.
// Migrate older saved files that still carry the former Weekly fallback.
if (($data['rcd_frequency_revision']??'')!==RCD_FREQUENCY_REVISION) {
    foreach ($data['equipment'] as &$rcdEquipment) {
        if (normalizedMachineId((string)($rcdEquipment['code']??''))!=='RCD-001') continue;
        $rcdEquipment['planned_frequency']='Q';
        $rcdEquipment['planned_frequencies']=['Q','H'];
        $rcdEquipment['schedule']=normalizePattern('-----------Q-----------H-----------Q-----------H----');
        $rcdEquipment['schedule_mode']='reference_calendar';
    }
    unset($rcdEquipment);
    $data['rcd_frequency_revision']=RCD_FREQUENCY_REVISION;
    saveData($data);
}

// One-time baseline: PMs already completed and documented using paper records.
if (($data['paper_records_completed_through'] ?? '') !== PAPER_RECORDS_COMPLETED_THROUGH) {
    $baselineDate=new DateTimeImmutable(PAPER_RECORDS_COMPLETED_THROUGH);
    $baselineYear=(int)$baselineDate->format('o');
    $baselineWeek=(int)$baselineDate->format('W');
    // Reopen any later week previously closed only by the automatic paper migration.
    $data['logs']=array_values(array_filter(($data['logs']??[]),function($log) use($baselineYear,$baselineWeek) {
        $isPaperMigration=($log['work_order']??'')==='Paper record migration';
        $year=(int)($log['year']??0); $week=(int)($log['week']??0);
        $afterBaseline=$year>$baselineYear || ($year===$baselineYear && $week>$baselineWeek);
        return !($isPaperMigration && $afterBaseline);
    }));
    $logged=[];
    foreach (($data['logs']??[]) as $log) $logged[$log['equipment_id'].'-'.$log['week'].'-'.$log['year']]=true;
    foreach ($data['equipment'] as $eq) foreach ($eq['schedule'] as $index=>$frequency) {
        $week=$index+1; $year=(int)$data['year']; $key=$eq['id'].'-'.$week.'-'.$year;
        if ($frequency!=='' && ($year<$baselineYear || ($year===$baselineYear && $week<=$baselineWeek)) && !isset($logged[$key])) {
            $data['logs'][]=['equipment_id'=>$eq['id'],'week'=>$week,'year'=>$year,'completed_on'=>PAPER_RECORDS_COMPLETED_THROUGH,'technician'=>'Existing paper PM records','work_order'=>'Paper record migration','remarks'=>'Completed before electronic PM monitoring started.','created_at'=>date('c')];
        }
    }
    $data['paper_records_completed_through']=PAPER_RECORDS_COMPLETED_THROUGH;
    saveData($data);
}

// First-run administrator account and security data.
$securityChanged=false;
if (!isset($data['aux_headers']) || !is_array($data['aux_headers']) || !isset($data['aux_information']) || !is_array($data['aux_information'])) {
    $data['aux_headers']=auxInformationHeaders(); $data['aux_information']=[];
    foreach (auxInformationSource() as $i=>$row) $data['aux_information'][]=['id'=>$i+1,'values'=>normalizeAuxInformationRow($row)];
    $data['next_aux_id']=count($data['aux_information'])+1; $securityChanged=true;
}
if (in_array('Machine Type',$data['aux_headers'],true) || count($data['aux_headers'])!==9) {
    foreach ($data['aux_information'] as &$auxMigrationRow) $auxMigrationRow['values']=normalizeAuxInformationRow(is_array($auxMigrationRow['values']??null)?$auxMigrationRow['values']:[]);
    unset($auxMigrationRow); $data['aux_headers']=auxInformationHeaders(); $securityChanged=true;
}
if (!isset($data['next_aux_id'])) { $data['next_aux_id']=1+max(array_map(fn($row)=>(int)($row['id']??0),$data['aux_information'])); $securityChanged=true; }
if (!isset($data['checklist_templates']) || !is_array($data['checklist_templates'])) { $data['checklist_templates']=initialChecklistTemplates(); $data['next_checklist_template_id']=2; $data['next_checklist_item_id']=29; $securityChanged=true; }
foreach ($data['checklist_templates'] as &$checklistMigration) if (!isset($checklistMigration['model_number'])) { $checklistMigration['model_number']=modelNumberForMachineId($data,(string)($checklistMigration['machine_id']??'')); if ($checklistMigration['model_number']==='' && strcasecmp((string)($checklistMigration['machine_id']??''),'PLV-001')===0) $checklistMigration['model_number']='VSP-88D PRO1 NEO'; unset($checklistMigration['machine_id'],$checklistMigration['machine_name']); $securityChanged=true; }
unset($checklistMigration);
if (!isset($data['next_checklist_template_id'])) { $data['next_checklist_template_id']=1+max(array_map(fn($row)=>(int)($row['id']??0),$data['checklist_templates'])); $securityChanged=true; }
if (!isset($data['next_checklist_item_id'])) { $maxItemId=0; foreach ($data['checklist_templates'] as $template) foreach (($template['items']??[]) as $item) $maxItemId=max($maxItemId,(int)($item['id']??0)); $data['next_checklist_item_id']=$maxItemId+1; $securityChanged=true; }
// Correct the original DBA-003 Equipment / Aux Information ID so every DBA machine resolves to model AD838.
foreach ($data['aux_information'] as &$auxDbaMigration) {
    $auxValues=normalizeAuxInformationRow($auxDbaMigration['values']??[]);
    if (normalizedMachineId($auxValues[0])==='BDA-003') { $auxValues[0]='DBA-003'; $auxDbaMigration['values']=$auxValues; $securityChanged=true; }
}
unset($auxDbaMigration,$auxValues);
// Align the Flux Cleaner ID with its checklist and Equipment / Aux Information record.
foreach ($data['equipment'] as &$equipmentCodeMigration) {
    if (normalizedMachineId((string)($equipmentCodeMigration['code']??''))==='FCA-001') { $equipmentCodeMigration['code']='FCG-001'; $securityChanged=true; }
}
unset($equipmentCodeMigration);
if (!findChecklistTemplateByModel($data,'POWER-C PLUS')) {
    $powerCItems=[];
    foreach (powerCPlusChecklistItems() as $row) $powerCItems[]=['id'=>$data['next_checklist_item_id']++,'check_item'=>$row['item'],'frequencies'=>$row['levels']];
    $data['checklist_templates'][]=['id'=>$data['next_checklist_template_id']++,'model_number'=>'POWER-C PLUS','items'=>$powerCItems];
    $securityChanged=true;
}
if (!findChecklistTemplateByModel($data,'POWERNEXX')) {
    $powerNexxItems=[];
    foreach (powerNexxChecklistItems() as $row) $powerNexxItems[]=['id'=>$data['next_checklist_item_id']++,'check_item'=>$row['item'],'frequencies'=>$row['levels']];
    $data['checklist_templates'][]=['id'=>$data['next_checklist_template_id']++,'model_number'=>'POWERNEXX','items'=>$powerNexxItems];
    $securityChanged=true;
}
if ((int)($data['checklist_schema_version']??0)<2) {
    foreach ($data['checklist_templates'] as &$template) {
        if (strcasecmp((string)($template['model_number']??''),'VSP-88D PRO1 NEO')===0) {
            $replacement=[]; foreach (powerCPlusChecklistItems() as $row) $replacement[]=['id'=>$data['next_checklist_item_id']++,'check_item'=>$row['item'],'frequencies'=>$row['levels']]; $template['items']=$replacement;
        } else foreach (($template['items']??[]) as &$item) unset($item['instruction']);
        unset($item);
    }
    unset($template);
    foreach (($data['logs']??[]) as &$historicalLog) foreach (($historicalLog['checklist']??[]) as &$historicalItem) unset($historicalItem['instruction']);
    unset($historicalItem,$historicalLog); $data['checklist_schema_version']=2; $securityChanged=true;
}
if ((int)($data['checklist_schema_version']??0)<3) {
    foreach ($data['checklist_templates'] as &$template) {
        if (in_array(strtoupper(trim((string)($template['model_number']??''))),['VSP-88D','VSP-88D PRO1','VSP-88D PRO1 NEO'],true)) {
            $replacement=[];
            foreach (plvChecklistItems() as $row) $replacement[]=['id'=>$data['next_checklist_item_id']++,'check_item'=>$row['item'],'frequencies'=>$row['levels']];
            $template['items']=$replacement;
        }
    }
    unset($template); $data['checklist_schema_version']=3; $securityChanged=true;
}
if ((int)($data['checklist_schema_version']??0)<4) {
    $ad838TemplateIndex=null;
    foreach ($data['checklist_templates'] as $templateIndex=>$template) {
        if (strcasecmp(trim((string)($template['model_number']??'')),'AD838')===0) { $ad838TemplateIndex=$templateIndex; break; }
    }
    $ad838Items=[];
    foreach (ad838ChecklistItems() as $row) $ad838Items[]=['id'=>$data['next_checklist_item_id']++,'check_item'=>$row['item'],'frequencies'=>$row['levels']];
    if ($ad838TemplateIndex===null) $data['checklist_templates'][]=['id'=>$data['next_checklist_template_id']++,'model_number'=>'AD838','items'=>$ad838Items];
    else $data['checklist_templates'][$ad838TemplateIndex]['items']=$ad838Items;
    $data['checklist_schema_version']=4; $securityChanged=true;
}
if ((int)($data['checklist_schema_version']??0)<5) {
    $attachedChecklistSets=[
        'SC700'=>fcgSc700ChecklistItems(),
        'VSP-88D PRO1 NEO'=>array_values(plvChecklistItems()),
        'R0832'=>roaR0832ChecklistItems(),
        'RAD-2000m/12'=>uvlRad2000ChecklistItems(),
        'NEL MSA840VIII'=>wmnNelMsa840ChecklistItems(),
        'DWR1722'=>wrdDwr1722ChecklistItems(),
        'DFD6362'=>wsdDfd6362ChecklistItems(),
    ];
    foreach ($attachedChecklistSets as $modelNumber=>$sourceItems) {
        $templateIndex=null;
        foreach ($data['checklist_templates'] as $index=>$template) if (strcasecmp(trim((string)($template['model_number']??'')),$modelNumber)===0) { $templateIndex=$index; break; }
        $replacement=[];
        foreach ($sourceItems as $row) $replacement[]=['id'=>$data['next_checklist_item_id']++,'check_item'=>$row['item'],'frequencies'=>$row['levels']];
        if ($templateIndex===null) $data['checklist_templates'][]=['id'=>$data['next_checklist_template_id']++,'model_number'=>$modelNumber,'items'=>$replacement];
        else $data['checklist_templates'][$templateIndex]['items']=$replacement;
    }
    $data['checklist_schema_version']=5; $securityChanged=true;
}
if ((int)($data['checklist_schema_version']??0)<6) {
    $rcdTemplateIndex=null;
    foreach ($data['checklist_templates'] as $index=>$template) {
        if (strcasecmp(trim((string)($template['model_number']??'')),'DFD6240')===0) { $rcdTemplateIndex=$index; break; }
    }
    $rcdItems=[];
    foreach (rcdDfd6240ChecklistItems() as $row) $rcdItems[]=['id'=>$data['next_checklist_item_id']++,'check_item'=>$row['item'],'frequencies'=>$row['levels']];
    if ($rcdTemplateIndex===null) $data['checklist_templates'][]=['id'=>$data['next_checklist_template_id']++,'model_number'=>'DFD6240','items'=>$rcdItems];
    else $data['checklist_templates'][$rcdTemplateIndex]['items']=$rcdItems;
    $data['checklist_schema_version']=6; $securityChanged=true;
}
if (!isset($data['users']) || !is_array($data['users']) || $data['users']===[]) {
    $data['users']=[['id'=>1,'name'=>'System Administrator','employee_no'=>'ADMIN','designation'=>'Administrator','password_hash'=>password_hash('Admin@123',PASSWORD_DEFAULT),'status'=>'approved','role'=>'admin','created_at'=>date('c'),'approved_at'=>date('c'),'approved_by'=>'SYSTEM']];
    $data['next_user_id']=2; $securityChanged=true;
}
if (!isset($data['next_user_id'])) { $data['next_user_id']=1+max(array_map(fn($user)=>(int)$user['id'],$data['users'])); $securityChanged=true; }
foreach ($data['users'] as &$securityUser) if (!isset($securityUser['email'])) { $securityUser['email']=''; $securityChanged=true; }
unset($securityUser);
foreach ($data['users'] as &$securityUser) if (!isset($securityUser['email_notifications'])) { $securityUser['email_notifications']=false; $securityChanged=true; }
unset($securityUser);
// Keep personnel names compatible with older enrollment records that may
// have used full_name, username or user_name instead of name.
foreach ($data['users'] as &$securityUser) {
    $storedName=trim((string)($securityUser['name']??''));
    if ($storedName==='') {
        foreach (['full_name','username','user_name'] as $legacyNameField) {
            $legacyName=trim((string)($securityUser[$legacyNameField]??''));
            if ($legacyName!=='') { $storedName=$legacyName; break; }
        }
    }
    if ($storedName!=='' && (string)($securityUser['name']??'')!==$storedName) { $securityUser['name']=$storedName; $securityChanged=true; }
    if ((string)($securityUser['full_name']??'')!==$storedName) { $securityUser['full_name']=$storedName; $securityChanged=true; }
}
unset($securityUser);
if (!isset($data['audit_logs']) || !is_array($data['audit_logs'])) { $data['audit_logs']=[]; $securityChanged=true; }
if ($securityChanged) saveData($data);
$currentUser=isset($_SESSION['user_id']) ? findUser($data,(int)$_SESSION['user_id']) : null;
if ($currentUser && ($currentUser['status']??'')!=='approved') { unset($_SESSION['user_id']); $currentUser=null; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($submittedToken === '' || !hash_equals((string)$_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(403);
        exit('Request rejected: invalid security token. Refresh the page and try again.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $employeeNo=strtoupper(trim((string)($_POST['employee_no']??''))); $password=(string)($_POST['password']??''); $matched=null;
        foreach ($data['users'] as $user) if (strtoupper((string)$user['employee_no'])===$employeeNo) { $matched=$user; break; }
        if (!$matched || !password_verify($password,(string)$matched['password_hash'])) setFlash('Invalid Employee No. or password.');
        elseif (($matched['status']??'')==='pending') setFlash('Your account is still waiting for Administrator approval.');
        elseif (($matched['status']??'')!=='approved') setFlash('This account is not authorized to log in.');
        else { session_regenerate_id(true); $_SESSION['user_id']=$matched['id']; auditAction($data,$matched,'LOGIN','User logged in.'); saveData($data); setFlash('Welcome, '.$matched['name'].'.'); }
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
    if ($action === 'signup') {
        $name=trim((string)($_POST['full_name']??($_POST['name']??''))); $employeeNo=strtoupper(trim((string)($_POST['employee_no']??''))); $email=strtolower(trim((string)($_POST['email']??''))); $wantsNotifications=$email!=='' && isset($_POST['email_notifications']); $designation=(string)($_POST['designation']??''); $password=(string)($_POST['password']??''); $confirm=(string)($_POST['confirm_password']??'');
        $duplicate=false; foreach ($data['users'] as $user) if (strtoupper((string)$user['employee_no'])===$employeeNo) $duplicate=true;
        if ($name==='' || $employeeNo==='' || !in_array($designation,['Technician','Operator','Engineer','Administrator'],true)) setFlash('Complete the required enrollment information: Name, Employee No., Designation, and Password.');
        elseif ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) setFlash('Enter a valid email address or leave it blank and add it later from My Profile.');
        elseif ($duplicate) setFlash('That Employee No. is already registered.');
        elseif (strlen($password)<8) setFlash('Password must contain at least 8 characters.');
        elseif ($password!==$confirm) setFlash('Password confirmation does not match.');
        else {
            [$photoPath,$photoError]=saveEnrollmentPhoto();
            if ($photoError!=='') setFlash($photoError);
            else {
                $data['users'][]=['id'=>$data['next_user_id']++,'name'=>$name,'full_name'=>$name,'employee_no'=>$employeeNo,'email'=>$email,'email_notifications'=>$wantsNotifications,'designation'=>$designation,'photo'=>$photoPath,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'status'=>'pending','role'=>'user','created_at'=>date('c'),'approved_at'=>null,'approved_by'=>null];
                auditAction($data,null,'SIGNUP_REQUEST',$employeeNo.' - '.$name.' requested access as '.$designation.'.'); saveData($data); setFlash('Enrollment submitted. Please wait for Administrator approval before logging in.');
            }
        }
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
    if ($action === 'logout') {
        if ($currentUser) { auditAction($data,$currentUser,'LOGOUT','User logged out.'); saveData($data); }
        session_destroy(); header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
    $loginActions=['complete_pm','callback_pm','set_frequency','save_equipment','delete_equipment','save_aux_information','delete_aux_information','create_checklist_template','delete_checklist_template','save_checklist_item','delete_checklist_item','reset_data','approve_user','reject_user','change_admin_password','update_profile','change_own_password','reset_user_password','send_due_email','send_test_email'];
    $adminActions=['set_frequency','save_equipment','delete_equipment','save_aux_information','delete_aux_information','create_checklist_template','delete_checklist_template','save_checklist_item','delete_checklist_item','reset_data','approve_user','reject_user','change_admin_password','reset_user_password','send_due_email','send_test_email'];
    if (in_array($action,$loginActions,true) && !$currentUser) {
        if ($action==='set_frequency') { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }
        setFlash('Please log in with an approved account to perform this transaction.'); header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
    if (in_array($action,$adminActions,true) && ($currentUser['role']??'')!=='admin') {
        if ($action==='set_frequency') { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Administrator access required']); exit; }
        setFlash('Administrator access is required for this action.'); header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
    if ($action === 'update_profile') {
        $email=strtolower(trim((string)($_POST['email']??''))); $wantsNotifications=$email!=='' && isset($_POST['email_notifications']); $photoPath=''; $photoError='';
        if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) setFlash('Enter a valid email address or leave it blank.');
        else {
            if (isset($_FILES['profile_photo']) && ($_FILES['profile_photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) [$photoPath,$photoError]=saveEnrollmentPhoto('profile_photo');
            if ($photoError!=='') setFlash($photoError);
            else { foreach ($data['users'] as &$user) if ((int)$user['id']===(int)$currentUser['id']) { $user['email']=$email; $user['email_notifications']=$wantsNotifications; if ($photoPath!=='') $user['photo']=$photoPath; } unset($user); auditAction($data,$currentUser,'UPDATE_PROFILE','Email, notification preference, or profile photo updated.'); saveData($data); setFlash('Profile updated successfully.'); }
        }
        header('Location: '.$_SERVER['PHP_SELF'].'?view=profile'); exit;
    }
    if ($action === 'change_own_password') {
        $currentPassword=(string)($_POST['current_password']??''); $newPassword=(string)($_POST['new_password']??''); $confirm=(string)($_POST['confirm_password']??'');
        if (!password_verify($currentPassword,(string)$currentUser['password_hash'])) setFlash('Current password is incorrect.');
        elseif (strlen($newPassword)<8) setFlash('New password must contain at least 8 characters.');
        elseif ($newPassword!==$confirm) setFlash('New password confirmation does not match.');
        else { foreach ($data['users'] as &$user) if ((int)$user['id']===(int)$currentUser['id']) $user['password_hash']=password_hash($newPassword,PASSWORD_DEFAULT); unset($user); auditAction($data,$currentUser,'CHANGE_PASSWORD','User changed own password.'); saveData($data); setFlash('Password changed successfully.'); }
        header('Location: '.$_SERVER['PHP_SELF'].'?view=profile'); exit;
    }
    if ($action === 'reset_user_password') {
        $targetId=(int)($_POST['user_id']??0); $newPassword=(string)($_POST['new_password']??'');
        if (strlen($newPassword)<8) setFlash('Reset password must contain at least 8 characters.');
        else { foreach ($data['users'] as &$user) if ((int)$user['id']===$targetId && ($user['status']??'')==='approved') { $user['password_hash']=password_hash($newPassword,PASSWORD_DEFAULT); auditAction($data,$currentUser,'RESET_USER_PASSWORD','Password reset for '.$user['employee_no'].' - '.$user['name'].'.'); } unset($user); saveData($data); setFlash('User password reset successfully.'); }
        header('Location: '.$_SERVER['PHP_SELF'].'?view=admin'); exit;
    }
    if ($action === 'send_test_email') {
        $adminEmail=trim((string)($currentUser['email']??''));
        $testResult=sendSmtpTestEmail($adminEmail,(string)($currentUser['name']??'Administrator'));
        auditAction($data,$currentUser,'SEND_TEST_EMAIL',$testResult['ok']?'SMTP test accepted for '.$adminEmail:$testResult['message']);
        saveData($data);
        setFlash($testResult['message']);
        header('Location: '.$_SERVER['PHP_SELF'].'?view=admin'); exit;
    }
    if ($action === 'send_due_email') {
        $result=sendDueEmailNotifications($data,true); auditAction($data,$currentUser,'SEND_DUE_EMAIL','Due items: '.$result['due'].', sent: '.$result['sent'].', failed: '.$result['failed'].'.'); saveData($data);
        setFlash('Email notification result: '.$result['sent'].' sent, '.$result['failed'].' failed for '.$result['due'].' open PM item(s).'); header('Location: '.$_SERVER['PHP_SELF'].'?view=admin'); exit;
    }
    if ($action === 'approve_user' || $action === 'reject_user') {
        $targetId=(int)($_POST['user_id']??0); $newStatus=$action==='approve_user'?'approved':'rejected';
        foreach ($data['users'] as &$user) if ((int)$user['id']===$targetId && ($user['role']??'')!=='admin') { $user['status']=$newStatus; $user['role']=($newStatus==='approved' && ($user['designation']??'')==='Administrator')?'admin':'user'; $user['approved_at']=$newStatus==='approved'?date('c'):null; $user['approved_by']=$currentUser['employee_no']; auditAction($data,$currentUser,strtoupper($newStatus).'_USER',$user['employee_no'].' - '.$user['name'].' as '.$user['designation']); }
        unset($user); saveData($data); setFlash('User request '.$newStatus.'.'); header('Location: '.$_SERVER['PHP_SELF'].'?view=admin'); exit;
    }
    if ($action === 'change_admin_password') {
        $currentPassword=(string)($_POST['current_password']??''); $newPassword=(string)($_POST['new_password']??''); $confirm=(string)($_POST['confirm_password']??'');
        if (!password_verify($currentPassword,(string)$currentUser['password_hash'])) setFlash('Current Administrator password is incorrect.');
        elseif (strlen($newPassword)<8) setFlash('New password must contain at least 8 characters.');
        elseif ($newPassword!==$confirm) setFlash('New password confirmation does not match.');
        else { foreach ($data['users'] as &$user) if ((int)$user['id']===(int)$currentUser['id']) $user['password_hash']=password_hash($newPassword,PASSWORD_DEFAULT); unset($user); auditAction($data,$currentUser,'CHANGE_PASSWORD','Administrator password changed.'); saveData($data); setFlash('Administrator password changed successfully.'); }
        header('Location: '.$_SERVER['PHP_SELF'].'?view=admin'); exit;
    }
    if ($action === 'set_frequency') {
        $id=(int)($_POST['id']??0); $week=(int)($_POST['week']??0); $freq=(string)($_POST['frequency']??'');
        $updatedDueDate='';
        if ($week>=1 && $week<=52 && in_array($freq,['','W','M','Q','H','A'],true)) {
            foreach ($data['equipment'] as &$eq) if ((int)$eq['id']===$id) {
                $eq['schedule'][$week-1]=$freq;
                if (!isset($eq['due_dates']) || !is_array($eq['due_dates'])) $eq['due_dates']=array_fill(0,52,'');
                if ($freq==='') $eq['due_dates'][$week-1]='';
                else {
                    $savedDate=(string)($eq['due_dates'][$week-1]??'');
                    $referenceDate=$savedDate!=='' ? new DateTimeImmutable($savedDate) : (new DateTimeImmutable())->setISODate((int)$data['year'],$week)->modify('+5 days');
                    $eq['due_dates'][$week-1]=advanceToPreviousScheduleDay($referenceDate)->format('Y-m-d');
                }
                $updatedDueDate=(string)($eq['due_dates'][$week-1]??'');
            }
            unset($eq); auditAction($data,$currentUser,'UPDATE_SCHEDULE','Equipment record '.$id.', WW'.$week.' changed to '.($freq?:'blank').'.'); saveData($data);
        }
        header('Content-Type: application/json'); echo json_encode(['ok'=>true,'due_date'=>$updatedDueDate]); exit;
    }
    if ($action === 'save_equipment') {
        $id=(int)($_POST['id']??0); $name=trim((string)($_POST['name']??'')); $code=trim((string)($_POST['code']??'')); $enrollmentDate=(string)($_POST['enrollment_date']??''); $startWeek=(int)($_POST['start_week']??0);
        $postedFrequencies=is_array($_POST['frequencies']??null)?array_map('strval',$_POST['frequencies']):[]; $frequencies=array_values(array_intersect(['W','M','Q','H','A'],$postedFrequencies)); $primaryFrequency=$frequencies[0]??'';
        $validDate=DateTimeImmutable::createFromFormat('!Y-m-d',$enrollmentDate); $dateErrors=DateTimeImmutable::getLastErrors();
        $validDate=$validDate!==false && ($dateErrors===false || (($dateErrors['warning_count']??0)===0 && ($dateErrors['error_count']??0)===0));
        if ($validDate && $startWeek>=1 && $startWeek<=52) {
            $weekday=(int)$validDate->format('N');
            $enrollmentDate=(new DateTimeImmutable())->setISODate((int)$data['year'],$startWeek,$weekday)->format('Y-m-d');
        }
        if ($name==='' || $code==='' || !$frequencies || !$validDate || $startWeek<1 || $startWeek>52) { setFlash('Equipment name, Equipment ID, at least one applicable frequency, a valid start date, and a starting work week are required.'); }
        elseif ($id===0) {
            $generated=scheduleFromEnrollmentDate($enrollmentDate,$frequencies,(int)$data['year']);
            $data['equipment'][]=['id'=>$data['next_id']++, 'name'=>$name, 'code'=>$code, 'schedule'=>$generated['schedule'], 'due_dates'=>$generated['due_dates'], 'planned_due_date'=>$enrollmentDate, 'planned_frequency'=>$primaryFrequency, 'planned_frequencies'=>$frequencies, 'enrolled_on'=>$enrollmentDate, 'start_week'=>$startWeek, 'schedule_mode'=>'enrollment_combined', 'schedule_rule_version'=>2];
            auditAction($data,$currentUser,'ADD_EQUIPMENT',$code.' - '.$name.', frequencies '.implode('/',$frequencies).' starting '.$enrollmentDate.'.'); saveData($data); setFlash('Equipment added. Its combined 52-week PM schedule was generated from '.$enrollmentDate.'.');
        } else {
            $generated=scheduleFromEnrollmentDate($enrollmentDate,$frequencies,(int)$data['year']);
            foreach ($data['equipment'] as &$eq) if ((int)$eq['id']===$id) { $eq['name']=$name; $eq['code']=$code; $eq['planned_frequency']=$primaryFrequency; $eq['planned_frequencies']=$frequencies; $eq['planned_due_date']=$enrollmentDate; $eq['enrolled_on']=$enrollmentDate; $eq['start_week']=$startWeek; $eq['schedule']=$generated['schedule']; $eq['due_dates']=$generated['due_dates']; $eq['schedule_mode']='enrollment_combined'; $eq['schedule_rule_version']=2; }
            unset($eq); auditAction($data,$currentUser,'EDIT_EQUIPMENT',$code.' - '.$name.', frequencies '.implode('/',$frequencies).' starting '.$enrollmentDate.'.'); saveData($data); setFlash('Equipment details and combined 52-week PM schedule updated.');
        }
    }
    if ($action === 'delete_equipment') {
        $id=(int)($_POST['id']??0);
        $data['equipment']=array_values(array_filter($data['equipment'],fn($eq)=>(int)$eq['id']!==$id));
        $data['logs']=array_values(array_filter($data['logs'],fn($log)=>(int)$log['equipment_id']!==$id));
        auditAction($data,$currentUser,'DELETE_EQUIPMENT','Equipment record '.$id.' removed.'); saveData($data); setFlash('Equipment removed.');
    }
    if ($action === 'save_aux_information') {
        $id=(int)($_POST['id']??0); $postedValues=is_array($_POST['values']??null)?$_POST['values']:[]; $values=[];
        for ($i=0;$i<count($data['aux_headers']);$i++) $values[]=trim((string)($postedValues[$i]??''));
        if (!array_filter($values,fn($value)=>$value!=='')) setFlash('Enter at least one equipment or auxiliary information value.');
        elseif ($id===0) {
            $id=$data['next_aux_id']++; $data['aux_information'][]=['id'=>$id,'values'=>$values];
            auditAction($data,$currentUser,'ADD_AUX_INFORMATION','Equipment / Aux Information record '.$id.' added.'); saveData($data); setFlash('Equipment / Aux Information record added.');
        } else {
            $updated=false;
            foreach ($data['aux_information'] as &$row) if ((int)($row['id']??0)===$id) { $row['values']=$values; $updated=true; break; }
            unset($row);
            if ($updated) { auditAction($data,$currentUser,'EDIT_AUX_INFORMATION','Equipment / Aux Information record '.$id.' updated.'); saveData($data); setFlash('Equipment / Aux Information record updated.'); }
            else setFlash('Equipment / Aux Information record was not found.');
        }
        header('Location: '.$_SERVER['PHP_SELF'].'?view=aux'); exit;
    }
    if ($action === 'delete_aux_information') {
        $id=(int)($_POST['id']??0); $before=count($data['aux_information']);
        $data['aux_information']=array_values(array_filter($data['aux_information'],fn($row)=>(int)($row['id']??0)!==$id));
        if (count($data['aux_information'])<$before) { auditAction($data,$currentUser,'DELETE_AUX_INFORMATION','Equipment / Aux Information record '.$id.' deleted.'); saveData($data); setFlash('Equipment / Aux Information record deleted.'); }
        else setFlash('Equipment / Aux Information record was not found.');
        header('Location: '.$_SERVER['PHP_SELF'].'?view=aux'); exit;
    }
    if ($action === 'create_checklist_template') {
        $modelNumber=trim((string)($_POST['model_number']??'')); $modelExists=false;
        foreach ($data['aux_information'] as $auxRow) { $values=normalizeAuxInformationRow($auxRow['values']??[]); if ($values[7]!=='' && strcasecmp($values[7],$modelNumber)===0) { $modelNumber=$values[7]; $modelExists=true; break; } }
        if (!$modelExists) setFlash('Enroll the Model Number in Equipment / Aux Information first.');
        elseif (findChecklistTemplateByModel($data,$modelNumber)) setFlash('A checklist template already exists for model '.$modelNumber.'.');
        else { $data['checklist_templates'][]=['id'=>$data['next_checklist_template_id']++,'model_number'=>$modelNumber,'items'=>[]]; auditAction($data,$currentUser,'CREATE_CHECKLIST_TEMPLATE',$modelNumber.' model checklist created.'); saveData($data); setFlash('Blank checklist created for model '.$modelNumber.'.'); }
        header('Location: '.$_SERVER['PHP_SELF'].'?view=checklists&model_number='.urlencode($modelNumber)); exit;
    }
    if ($action === 'delete_checklist_template') {
        $templateId=(int)($_POST['template_id']??0); $data['checklist_templates']=array_values(array_filter($data['checklist_templates'],fn($template)=>(int)$template['id']!==$templateId)); auditAction($data,$currentUser,'DELETE_CHECKLIST_TEMPLATE','Checklist template '.$templateId.' deleted.'); saveData($data); setFlash('Checklist template deleted.'); header('Location: '.$_SERVER['PHP_SELF'].'?view=checklists'); exit;
    }
    if ($action === 'save_checklist_item') {
        $templateId=(int)($_POST['template_id']??0); $itemId=(int)($_POST['item_id']??0); $checkItem=trim((string)($_POST['check_item']??'')); $posted=is_array($_POST['frequencies']??null)?array_map('strval',$_POST['frequencies']):[]; $frequencies=array_values(array_intersect(['W','M','Q','H','A'],$posted)); $modelNumber='';
        if ($checkItem==='' || !$frequencies) setFlash('Check Item and at least one frequency are required.');
        else foreach ($data['checklist_templates'] as &$template) if ((int)$template['id']===$templateId) { $modelNumber=$template['model_number']; if ($itemId===0) $template['items'][]=['id'=>$data['next_checklist_item_id']++,'check_item'=>$checkItem,'frequencies'=>$frequencies]; else foreach ($template['items'] as &$item) if ((int)$item['id']===$itemId) { $item=['id'=>$itemId,'check_item'=>$checkItem,'frequencies'=>$frequencies]; break; } unset($item); auditAction($data,$currentUser,$itemId===0?'ADD_CHECKLIST_ITEM':'EDIT_CHECKLIST_ITEM',$modelNumber.' - '.$checkItem); saveData($data); setFlash('Checklist item saved.'); break; } unset($template);
        header('Location: '.$_SERVER['PHP_SELF'].'?view=checklists&model_number='.urlencode($modelNumber)); exit;
    }
    if ($action === 'delete_checklist_item') {
        $templateId=(int)($_POST['template_id']??0); $itemId=(int)($_POST['item_id']??0); $modelNumber=''; foreach ($data['checklist_templates'] as &$template) if ((int)$template['id']===$templateId) { $modelNumber=$template['model_number']; $template['items']=array_values(array_filter($template['items']??[],fn($item)=>(int)$item['id']!==$itemId)); break; } unset($template); auditAction($data,$currentUser,'DELETE_CHECKLIST_ITEM',$modelNumber.' checklist item '.$itemId.' deleted.'); saveData($data); setFlash('Checklist item deleted.'); header('Location: '.$_SERVER['PHP_SELF'].'?view=checklists&model_number='.urlencode($modelNumber)); exit;
    }
    if ($action === 'complete_pm') {
        $id=(int)($_POST['id']??0); $week=(int)($_POST['week']??0); $frequency=(string)($_POST['frequency']??''); $equipmentRecord=null;
        foreach ($data['equipment'] as $eq) if ((int)$eq['id']===$id) { $equipmentRecord=$eq; break; }
        $checklist=[]; $validationError='';
        if (!$equipmentRecord || $week<1 || $week>53 || !in_array($frequency,['W','M','Q','H','A'],true)) $validationError='Invalid PM completion request.';
        else {
            $scheduledFrequency=(string)($equipmentRecord['schedule'][$week-1]??'');
            $expectedFrequency=$scheduledFrequency!=='' ? $scheduledFrequency : (string)($equipmentRecord['planned_frequency']??'');
            if ($frequency!==$expectedFrequency) $validationError='The PM frequency does not match the selected work-week schedule.';
        }
        $template=$equipmentRecord?findChecklistTemplate($data,(string)$equipmentRecord['code']):null;
        if ($validationError==='' && $template) {
            $requiredItems=array_values(array_filter($template['items']??[],fn($row)=>checklistItemAppliesToFrequency($row['frequencies']??[],$frequency))); $results=$_POST['check_result']??[]; $findings=$_POST['check_remarks']??[];
            foreach ($requiredItems as $number=>$row) {
                $itemId=(int)$row['id']; $result=(string)($results[$itemId]??''); $remark=trim((string)($findings[$itemId]??''));
                if (!in_array($result,['OK','NG','N/A'],true)) { $validationError='Complete every required checklist result.'; break; }
                if ($result==='NG' && $remark==='') { $validationError='Enter remarks for every NG result.'; break; }
                $checklist[]=['no'=>$number+1,'item'=>$row['check_item'],'result'=>$result,'remarks'=>$remark];
            }
        }
        if ($validationError!=='') setFlash($validationError);
        else {
            $data['logs'][]=['equipment_id'=>$id,'equipment_code'=>$equipmentRecord['code'],'frequency'=>$frequency,'week'=>$week,'year'=>(int)$data['year'],'completed_on'=>$_POST['completed_on']??date('Y-m-d'),'technician'=>$currentUser['name'].' ('.$currentUser['employee_no'].')','user_id'=>$currentUser['id'],'work_order'=>'FF ASM 10','remarks'=>trim((string)($_POST['remarks']??'')),'checklist'=>$checklist,'created_at'=>date('c')];
            auditAction($data,$currentUser,'COMPLETE_PM',$equipmentRecord['code'].', WW'.$week.', '.$frequency.' PM marked complete with '.count($checklist).' checklist item(s).'); saveData($data); setFlash('PM completion recorded under '.$currentUser['name'].'.');
        }
    }
    if ($action === 'callback_pm') {
        $recordIndex=(int)($_POST['record_index']??-1); $equipmentId=(int)($_POST['equipment_id']??0); $recordWeek=max(1,min(52,(int)($_POST['record_ww']??0))); $callbackAction=trim((string)($_POST['callback_action']??''));
        $results=is_array($_POST['callback_result']??null)?$_POST['callback_result']:[]; $remarks=is_array($_POST['callback_remarks']??null)?$_POST['callback_remarks']:[]; $validationError='';
        $log=$data['logs'][$recordIndex]??null;
        if (!is_array($log) || (int)($log['equipment_id']??0)!==$equipmentId || (int)($log['week']??0)!==$recordWeek) $validationError='The selected PM record could not be verified.';
        elseif ($callbackAction==='') $validationError='Enter the action performed during the callback.';
        elseif (empty($log['checklist']) || !is_array($log['checklist'])) $validationError='This PM record has no checklist available for callback.';
        else {
            $updatedChecklist=[];
            foreach ($log['checklist'] as $index=>$storedItem) {
                $result=(string)($results[$index]??''); $itemRemark=trim((string)($remarks[$index]??''));
                if (!in_array($result,['OK','NG','N/A'],true)) { $validationError='Complete every callback checklist result.'; break; }
                if ($result==='NG' && $itemRemark==='') { $validationError='Enter remarks for every NG callback result.'; break; }
                $updatedChecklist[]=['no'=>(int)($storedItem['no']??($index+1)),'item'=>(string)($storedItem['item']??''),'result'=>$result,'remarks'=>$itemRemark];
            }
        }
        if ($validationError!=='') setFlash($validationError);
        else {
            if (!isset($data['logs'][$recordIndex]['callback_history']) || !is_array($data['logs'][$recordIndex]['callback_history'])) $data['logs'][$recordIndex]['callback_history']=[];
            $callbackNumber=count($data['logs'][$recordIndex]['callback_history'])+1;
            $data['logs'][$recordIndex]['callback_history'][]=[
                'callback_no'=>$callbackNumber,
                'updated_at'=>date('c'),
                'updated_by'=>$currentUser['name'].' ('.$currentUser['employee_no'].')',
                'user_id'=>$currentUser['id'],
                'action_remarks'=>$callbackAction,
                'previous_checklist'=>$data['logs'][$recordIndex]['checklist'],
                'previous_overall_remarks'=>(string)($data['logs'][$recordIndex]['remarks']??'')
            ];
            $data['logs'][$recordIndex]['checklist']=$updatedChecklist;
            $data['logs'][$recordIndex]['last_callback_action']=$callbackAction;
            $data['logs'][$recordIndex]['last_callback_at']=date('c');
            $data['logs'][$recordIndex]['last_callback_by']=$currentUser['name'].' ('.$currentUser['employee_no'].')';
            auditAction($data,$currentUser,'PM_CALLBACK',(string)($log['equipment_code']??$equipmentId).', WW'.$recordWeek.', callback '.$callbackNumber.': '.$callbackAction);
            saveData($data); setFlash('PM callback updated successfully. The original completion and callback history were retained.');
        }
        header('Location: '.$_SERVER['PHP_SELF'].'?view=records&equipment_id='.$equipmentId.'&record_ww='.$recordWeek); exit;
    }
    if ($action === 'reset_data') { $users=$data['users']; $nextUserId=$data['next_user_id']; $auditLogs=$data['audit_logs']; $data=initialData(); $data['users']=$users; $data['next_user_id']=$nextUserId; $data['audit_logs']=$auditLogs; auditAction($data,$currentUser,'RESET_SCHEDULE','Reference schedule restored.'); saveData($data); setFlash('Reference schedule restored.'); }
    $returnWeek=isset($_POST['return_ww']) ? max(1,min(53,(int)$_POST['return_ww'])) : 0;
    header('Location: '.$_SERVER['PHP_SELF'].($returnWeek?'?view=dashboard&ww='.$returnWeek:'')); exit;
}

$completed=[];
foreach ($data['logs'] as $log) $completed[$log['equipment_id'].'-'.$log['week']]=true;
$currentWeek=(int)date('W');
$selectedWeek=max(1,min(53,(int)($_GET['ww']??$currentWeek)));
$requestedView=(string)($_GET['view']??'dashboard');
$isAdministrator=(($currentUser['role']??'')==='admin' || ($currentUser['designation']??'')==='Administrator');
$view=in_array($requestedView,['manage','profile','records'],true) ? $requestedView : ((in_array($requestedView,['admin','aux','checklists','kpi'],true) && $isAdministrator) ? $requestedView : 'dashboard');
$scheduled=$done=$overdue=0;
foreach ($data['equipment'] as $eq) foreach ($eq['schedule'] as $i=>$f) if ($f!=='') {
    $scheduled++; $key=$eq['id'].'-'.($i+1);
    if (isset($completed[$key])) $done++; elseif (($i+1)<$currentWeek && (int)$data['year']<=(int)date('Y')) $overdue++;
}
$kpiFrequency=strtoupper(trim((string)($_GET['kpi_frequency']??'')));
if (!in_array($kpiFrequency,['','W','M','Q','H','A'],true)) $kpiFrequency='';
$kpiStartWeek=max(1,min(52,(int)($_GET['kpi_start_ww']??1)));
$kpiTrendPeriod=strtolower(trim((string)($_GET['kpi_trend_period']??'monthly')));
if (!in_array($kpiTrendPeriod,['monthly','quarterly'],true)) $kpiTrendPeriod='monthly';
$kpiOverdueItems=[]; $kpiCallbackItems=[]; $equipmentById=[];
foreach ($data['equipment'] as $eq) {
    $equipmentById[(int)$eq['id']]=$eq;
    foreach (($eq['schedule']??[]) as $i=>$scheduledFrequency) {
        $scheduledFrequency=frequencyCode((string)$scheduledFrequency);
        if ($scheduledFrequency==='' || ($kpiFrequency!=='' && $scheduledFrequency!==$kpiFrequency)) continue;
        $week=$i+1;
        if ($week<$kpiStartWeek) continue;
        $key=$eq['id'].'-'.$week;
        if (isset($completed[$key])) continue;
        $dueDate=(string)($eq['due_dates'][$i]??'');
        if ($dueDate==='' || $dueDate>=date('Y-m-d')) continue;
        $kpiOverdueItems[]=['name'=>$eq['name'],'code'=>$eq['code'],'week'=>$week,'due_date'=>$dueDate,'frequency'=>$scheduledFrequency];
    }
}
foreach (($data['logs']??[]) as $log) {
    $logFrequency=frequencyCode((string)($log['frequency']??''));
    if ($kpiFrequency!=='' && $logFrequency!==$kpiFrequency) continue;
    if ((int)($log['week']??0)<$kpiStartWeek) continue;
    $logEquipment=$equipmentById[(int)($log['equipment_id']??0)]??null;
    foreach ((is_array($log['callback_history']??null)?$log['callback_history']:[]) as $callback) {
        $kpiCallbackItems[]=[
            'name'=>(string)($logEquipment['name']??''), 'code'=>(string)($log['equipment_code']??($logEquipment['code']??'')),
            'week'=>(int)($log['week']??0), 'frequency'=>$logFrequency,
            'callback_no'=>(int)($callback['callback_no']??0), 'updated_at'=>(string)($callback['updated_at']??''),
            'updated_by'=>(string)($callback['updated_by']??''), 'remarks'=>(string)($callback['action_remarks']??'')
        ];
    }
}
usort($kpiOverdueItems,fn($a,$b)=>strcmp($a['due_date'],$b['due_date']));
usort($kpiCallbackItems,fn($a,$b)=>strcmp($b['updated_at'],$a['updated_at']));
$kpiOverdueTrend=array_fill(1,12,0); $kpiCallbackTrend=array_fill(1,12,0);
foreach ($kpiOverdueItems as $item) if ((int)date('Y',strtotime($item['due_date']))===(int)$data['year']) $kpiOverdueTrend[(int)date('n',strtotime($item['due_date']))]++;
foreach ($kpiCallbackItems as $item) if ($item['updated_at']!=='' && (int)date('Y',strtotime($item['updated_at']))===(int)$data['year']) $kpiCallbackTrend[(int)date('n',strtotime($item['updated_at']))]++;
$kpiStartDate=(new DateTimeImmutable())->setISODate((int)$data['year'],$kpiStartWeek);
$kpiStartMonth=(int)$kpiStartDate->format('n');
$kpiTrendLabels=[]; $kpiOverdueSeries=[]; $kpiCallbackSeries=[];
if ($kpiTrendPeriod==='quarterly') {
    $startQuarter=(int)ceil($kpiStartMonth/3);
    for ($quarter=$startQuarter;$quarter<=4;$quarter++) {
        $kpiTrendLabels[]='Q'.$quarter; $overdueValue=0; $callbackValue=0;
        for ($month=(($quarter-1)*3)+1;$month<=$quarter*3;$month++) { $overdueValue+=$kpiOverdueTrend[$month]; $callbackValue+=$kpiCallbackTrend[$month]; }
        $kpiOverdueSeries[]=$overdueValue; $kpiCallbackSeries[]=$callbackValue;
    }
} else {
    for ($month=$kpiStartMonth;$month<=12;$month++) { $kpiTrendLabels[]=date('M',mktime(0,0,0,$month,1)); $kpiOverdueSeries[]=$kpiOverdueTrend[$month]; $kpiCallbackSeries[]=$kpiCallbackTrend[$month]; }
}
$kpiTrendMax=max(1,max($kpiOverdueSeries),max($kpiCallbackSeries));
$kpiChartX=function(int $index,int $count):float { return $count<=1?505:55+($index*(900/($count-1))); };
$kpiChartPoints=function(array $values,int $maximum) use ($kpiChartX):string { $points=[]; $count=count($values); foreach (array_values($values) as $index=>$value) { $x=$kpiChartX($index,$count); $y=235-(($value/$maximum)*185); $points[]=round($x,1).','.round($y,1); } return implode(' ',$points); };
$dueItems=[];
$scheduledThisWeek=0; $completedThisWeek=0;
foreach ($data['equipment'] as $eq) {
    $frequency=(string)($eq['schedule'][$selectedWeek-1]??'');
    $dueDate=(string)($eq['due_dates'][$selectedWeek-1]??'');
    if (($eq['schedule_mode']??'')!=='enrollment_combined' && ($frequency==='' || $dueDate==='')) {
        $recurrenceFrequency=(string)($eq['planned_frequency']??'');
        $dueDate=dueOccurrenceForWorkWeek((string)($eq['planned_due_date']??''),$recurrenceFrequency,(int)$data['year'],$selectedWeek)??'';
        if ($frequency==='') $frequency=$recurrenceFrequency;
    }
    if ($dueDate==='' || $frequency==='') continue;
    $scheduledThisWeek++;
    $key=$eq['id'].'-'.$selectedWeek;
    if (isset($completed[$key])) { $completedThisWeek++; continue; }
    $dueItems[]=[
        'id'=>$eq['id'], 'name'=>$eq['name'], 'code'=>$eq['code'],
        'frequency'=>$frequency, 'completed'=>false,
        'due_date'=>$dueDate, 'week'=>$selectedWeek
    ];
}
usort($dueItems,fn($a,$b)=>strcmp($a['due_date'],$b['due_date']));
$dueThisWeek=count($dueItems);
$selectedOverdue=count(array_filter($dueItems,fn($item)=>$item['due_date']<date('Y-m-d')));
$selectedWeekStart=(new DateTimeImmutable())->setISODate((int)$data['year'],$selectedWeek);
$selectedWeekEnd=$selectedWeekStart->modify('+6 days');
$recordEquipmentId=max(0,(int)($_GET['equipment_id']??0));
$recordWorkWeek=max(0,min(52,(int)($_GET['record_ww']??0)));
$recordSearchSubmitted=$view==='records' && array_key_exists('equipment_id',$_GET) && array_key_exists('record_ww',$_GET);
$recordEquipment=null; $recordLogs=[];
if ($recordEquipmentId>0 && $recordWorkWeek>0) {
    foreach ($data['equipment'] as $eq) if ((int)$eq['id']===$recordEquipmentId) { $recordEquipment=$eq; break; }
    if ($recordEquipment) {
        foreach ($data['logs'] as $logIndex=>$log) if ((int)($log['equipment_id']??0)===$recordEquipmentId && (int)($log['week']??0)===$recordWorkWeek) { $log['_record_index']=$logIndex; $recordLogs[]=$log; }
        usort($recordLogs,fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));
    }
}
$auxMachineIds=[]; $auxMachineNames=[]; $auxCategories=[]; $auxModelNumbers=[]; $equipmentModelMap=[];
foreach (($data['aux_information']??[]) as $auxFilterRow) {
    $auxValues=normalizeAuxInformationRow(is_array($auxFilterRow['values']??null)?$auxFilterRow['values']:[]);
    if ($auxValues[0]!=='') $auxMachineIds[$auxValues[0]]=$auxValues[0];
    if ($auxValues[1]!=='') $auxMachineNames[$auxValues[1]]=$auxValues[1];
    if ($auxValues[4]!=='') $auxCategories[$auxValues[4]]=$auxValues[4];
    if ($auxValues[7]!=='') $auxModelNumbers[$auxValues[7]]=$auxValues[7];
    if ($auxValues[0]!=='' && $auxValues[7]!=='') $equipmentModelMap[strtolower(normalizedMachineId($auxValues[0]))]=$auxValues[7];
}
natcasesort($auxMachineIds); natcasesort($auxMachineNames); natcasesort($auxCategories); natcasesort($auxModelNumbers);
$checklistModelNumber=trim((string)($_GET['model_number']??'')); $selectedChecklistTemplate=$checklistModelNumber!==''?findChecklistTemplateByModel($data,$checklistModelNumber):null;
$calendarWeeks=[]; $calendarMonths=[]; $calendarQuarters=[];
for ($calendarWeek=1;$calendarWeek<=52;$calendarWeek++) {
    $weekStart=(new DateTimeImmutable())->setISODate((int)$data['year'],$calendarWeek);
    $representativeDate=$weekStart->modify('+3 days'); // Thursday assigns cross-month weeks consistently.
    $monthKey=$representativeDate->format('Y-m'); $monthLabel=strtoupper($representativeDate->format('F'));
    $quarterNumber=(int)ceil(((int)$representativeDate->format('n'))/3); $quarterKey=$representativeDate->format('Y').'-Q'.$quarterNumber;
    $calendarWeeks[]=['week'=>$calendarWeek,'start'=>$weekStart,'month_key'=>$monthKey,'quarter_key'=>$quarterKey];
    if (!$calendarMonths || $calendarMonths[count($calendarMonths)-1]['key']!==$monthKey) $calendarMonths[]=['key'=>$monthKey,'label'=>$monthLabel,'span'=>1];
    else $calendarMonths[count($calendarMonths)-1]['span']++;
    if (!$calendarQuarters || $calendarQuarters[count($calendarQuarters)-1]['key']!==$quarterKey) $calendarQuarters[]=['key'=>$quarterKey,'label'=>['1st QUARTER','2nd QUARTER','3rd QUARTER','4th QUARTER'][$quarterNumber-1],'span'=>1];
    else $calendarQuarters[count($calendarQuarters)-1]['span']++;
}
if ($_SERVER['REQUEST_METHOD']==='GET' && $currentUser) {
    $emailResult=sendDueEmailNotifications($data,false);
    if (($emailResult['sent']+$emailResult['failed'])>0) { auditAction($data,null,'AUTOMATIC_DUE_EMAIL','Due items: '.$emailResult['due'].', sent: '.$emailResult['sent'].', failed: '.$emailResult['failed'].'.'); saveData($data); }
}
$flash=$_SESSION['flash']??''; unset($_SESSION['flash']);
?>
<?php
ob_start(static function (string $html): string {
    $token = htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8');
    $html = preg_replace('/<head([^>]*)>/i', '<head$1><meta name="csrf-token" content="' . $token . '">', $html, 1) ?? $html;
    $html = preg_replace_callback('/<form\b(?=[^>]*\bmethod="post")[^>]*>/i', static function ($match) use ($token) {
        return $match[0] . '<input type="hidden" name="_csrf" value="' . $token . '">';
    }, $html) ?? $html;
    return $html;
});
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Preventive Maintenance Electronic System</title>
<style>
:root{--navy:#5f171c;--blue:#a5262e;--light:#f8eeee;--green:#287a4b;--red:#b3262e;--amber:#c87612;--text:#352a2b}
*{box-sizing:border-box} body{margin:0;font:14px Arial,sans-serif;background:#f7f5f4;color:var(--text)}
header{background:linear-gradient(115deg,#7f1d24,#a5262e);color:#fff;padding:22px 28px;display:flex;align-items:center;justify-content:space-between;gap:20px;border-bottom:5px solid #d7b16d} header h1{margin:0 0 5px;font-size:24px;letter-spacing:.2px} header p{margin:0;opacity:.88}.account{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.account-info{text-align:right}.account-info b,.account-info small{display:block}.avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid #fff}.approval-photo{width:58px;height:58px;border-radius:8px;object-fit:cover;border:1px solid #cfbfc0}
.wrap{max-width:1600px;margin:auto;padding:20px}.cards{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.card,.panel{background:#fff;border-radius:10px;box-shadow:0 2px 12px #5f171c14}.card{padding:16px;border-left:5px solid var(--blue)}.card b{font-size:25px;display:block;margin-top:5px}
.login-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background-color:#eee8e7;background-image:linear-gradient(#a5262e0a 1px,transparent 1px),linear-gradient(90deg,#a5262e0a 1px,transparent 1px),radial-gradient(circle at 15% 20%,#a5262e18 0 2px,transparent 3px),radial-gradient(circle at 85% 75%,#a5262e18 0 2px,transparent 3px);background-size:34px 34px,34px 34px,90px 90px,110px 110px}.login-card{position:relative;isolation:isolate;overflow:hidden;width:min(500px,100%);background:#fffffff5;border-radius:18px;padding:34px 46px 40px;border-top:8px solid var(--blue);box-shadow:0 20px 55px #5f171c2e,8px 8px 0 #dccbcc}.login-card:before{content:"KAYNES\A SEMICON";white-space:pre;position:absolute;z-index:-2;top:86px;left:50%;transform:translateX(-50%);text-align:center;font-weight:900;font-size:66px;line-height:.86;letter-spacing:8px;color:#a5262e0a}.login-card:after{content:"";position:absolute;z-index:-1;inset:0;background:linear-gradient(135deg,transparent 0 72%,#a5262e08 72% 73%,transparent 73%),linear-gradient(45deg,transparent 0 82%,#a5262e08 82% 83%,transparent 83%);pointer-events:none}.brand-lockup{text-align:center;margin-bottom:25px}.brand-name{color:#a5262e;font-weight:900;font-size:27px;letter-spacing:3px}.brand-name span{display:block;font-size:13px;letter-spacing:8px;margin:3px 0 13px}.industry-tag{display:inline-block;padding:5px 11px;border:1px solid #d9b9bb;border-radius:20px;color:#765e60;font-size:10px;font-weight:700;letter-spacing:1.2px}.login-card h1{text-align:center;color:var(--navy);font-size:26px;margin:0 0 28px}.login-card .field{margin-bottom:20px;font-size:16px;font-weight:700}.login-card input{height:56px;font-size:16px;padding:12px 16px;background:#ffffffd9}.login-card .login-submit{width:100%;height:52px;font-size:16px}.signup-area{text-align:center;border-top:1px solid #eadcdd;margin-top:24px;padding-top:22px}.signup-area p{color:#765e60;margin:0 0 12px}.signup-area button{width:100%;background:#765e60}
.toolbar{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-bottom:12px}.btn,button{border:0;border-radius:6px;padding:9px 13px;background:var(--blue);color:#fff;cursor:pointer;font-weight:600}.btn.secondary{background:#765e60}.btn.danger{background:#d0383e}
.nav{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;overflow:visible}.nav a{text-decoration:none;flex:0 0 auto}.nav .active{background:var(--navy)}.nav .kpi-menu{background:#b3242d;color:#fff;border:2px solid #b3242d}.nav .kpi-menu.active{background:#68191e;border-color:#68191e}
.due-table{width:100%;min-width:820px;font-size:14px}.due-table th{position:static;background:var(--navy);color:#fff}.due-table td{text-align:left;padding:12px}.due-date-form{display:flex;align-items:center;gap:6px;margin:0}.due-date-form input{width:145px}.badge{display:inline-block;padding:5px 10px;border-radius:20px;color:#fff;font-weight:700}.status-due{background:var(--red)}.status-done{background:var(--green)}.pm-warning-row td,.pm-upcoming-row td{background:#fff6bf}.status-warning{background:#d69400;color:#211700}.status-overdue{background:#b4232a}.pm-upcoming-row td{animation:pmAdvanceBlink 1s steps(2,start) infinite}.status-alarm{animation:pmBadgeBlink 1s steps(2,start) infinite}.sound-alarm-on{background:#168454!important}.sound-alarm-alert{background:#b4232a!important;box-shadow:0 0 0 3px #ffd84d}@keyframes pmAdvanceBlink{50%{background:#ffd84d}}@keyframes pmBadgeBlink{50%{opacity:.35;box-shadow:0 0 0 4px #ffd84d}}@media(prefers-reduced-motion:reduce){.pm-upcoming-row td,.status-alarm{animation:none}.status-alarm{box-shadow:0 0 0 3px #ffd84d}}
input,select,textarea{border:1px solid #cfbfc0;border-radius:6px;padding:8px;background:#fff}input:focus,select:focus,textarea:focus{outline:2px solid #dca4a7;border-color:var(--blue)}.search{min-width:250px}.panel{padding:14px}.table-wrap{overflow:auto;max-height:68vh;border:1px solid #dfd2d3;border-radius:7px}
table{border-collapse:separate;border-spacing:0;min-width:2400px;width:100%;font-size:12px}th,td{border-right:1px solid #e5d9da;border-bottom:1px solid #e5d9da;text-align:center;padding:6px;white-space:nowrap}thead th{position:sticky;top:0;background:var(--navy);color:#fff;z-index:3}.sticky{position:sticky;left:0;background:#fff;z-index:2}.sticky2{position:sticky;left:45px;background:#fff;z-index:2;text-align:left;min-width:245px}.sticky3{position:sticky;left:290px;background:#fff;z-index:2;min-width:92px}thead .sticky,thead .sticky2,thead .sticky3{z-index:5;background:var(--navy)}
.schedule-calendar{width:100%;min-width:2400px;table-layout:fixed;font-size:12px}.schedule-calendar thead th{border-color:#8f5559;padding:4px}.schedule-calendar .calendar-quarter{top:0;height:27px;background:#5f171c;font-size:11px;letter-spacing:.2px}.schedule-calendar .calendar-month{top:27px;height:25px;background:#7f1d24;font-size:10px}.schedule-calendar .calendar-week{top:52px;height:44px;background:#a5262e;width:38px;min-width:38px;padding:3px 1px;font-size:10px;overflow:hidden}.schedule-calendar .calendar-week small{display:block;font-size:8px;margin-top:3px;font-weight:400;line-height:1}.schedule-calendar thead .calendar-fixed{top:0;vertical-align:middle}.schedule-calendar .sticky{left:0;width:45px;min-width:45px;padding:4px}.schedule-calendar .sticky2{left:45px;width:245px;min-width:245px;max-width:245px;padding:5px 8px;overflow:hidden;text-overflow:ellipsis}.schedule-calendar .sticky3{left:290px;width:92px;min-width:92px;max-width:92px;padding:5px;overflow:hidden;text-overflow:ellipsis}.schedule-calendar thead th:last-child,.schedule-calendar tbody td:last-child{width:105px;min-width:105px}.schedule-calendar tbody tr:nth-child(even) td{background:#fcf8f8}.schedule-calendar tbody tr:hover td{background:#fff4dc}.schedule-calendar tbody tr:hover .sticky,.schedule-calendar tbody tr:hover .sticky2,.schedule-calendar tbody tr:hover .sticky3{background:#fff4dc}.schedule-calendar td{height:46px;padding:3px}.schedule-calendar .freq{width:32px;height:34px;padding:0;line-height:1;border-radius:5px}.schedule-calendar .freq-code{font-weight:800;font-size:11px}.schedule-calendar .freq-date{display:none}.schedule-calendar .actions{gap:3px;justify-content:center}.schedule-calendar .actions .small{padding:5px 6px;font-size:10px}
.freq{display:inline-flex;align-items:center;justify-content:center;width:31px;height:29px;padding:0;border-radius:5px;color:#fff;font-size:11px}.f-W{background:#3b82f6}.f-M{background:#16a34a}.f-Q{background:#9333ea}.f-H{background:#e9820b}.f-A{background:#dc2626}.f-none{background:#e5eaf0;color:#778392}.done{outline:3px solid #19c37d;outline-offset:-3px}.overdue{box-shadow:inset 0 -4px #d72f2f}.today{background:#fff5c2!important;color:#17223a!important}.legend{display:flex;gap:13px;flex-wrap:wrap;margin:10px 0}.dot{display:inline-block;width:11px;height:11px;border-radius:3px;margin-right:4px}
.modal{display:none;position:fixed;inset:0;background:#3b0b0f99;z-index:20;align-items:center;justify-content:center;padding:14px}.modal.show{display:flex}.modal-box{width:min(520px,94vw);max-height:calc(100vh - 28px);overflow:auto;background:#fff;border-radius:12px;border-top:6px solid var(--blue);padding:20px}.modal-box h2{margin-top:0;color:var(--navy)}.grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px}.grid>*{min-width:0}.field{display:flex;flex-direction:column;gap:5px;min-width:0}.field>input:not([type=checkbox]),.field>select,.field>textarea{width:100%;min-width:0}.full{grid-column:1/-1}.flash{padding:11px 15px;background:#f8eeee;border:1px solid #dca4a7;color:#68191e;border-radius:7px;margin-bottom:12px}.actions{display:flex;gap:5px}.small{padding:5px 8px;font-size:11px}
#equipmentModal .modal-box{width:min(880px,96vw)}#equipmentModal fieldset{min-width:0;margin:0}.frequency-choices{display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:8px;margin:7px 0}.frequency-choices label{display:flex;align-items:center;gap:7px;padding:9px;border:1px solid #dfd2d3;border-radius:6px;background:#faf5f5;white-space:nowrap;min-width:0}
.record-meta{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:10px;margin:14px 0}.record-meta>div{padding:10px;background:#faf5f5;border-left:3px solid var(--blue);white-space:normal}.record-checklist td{text-align:left;vertical-align:top}
.checklist-template .table-wrap{overflow-x:hidden}.blank-checklist-table{width:100%;min-width:0!important;table-layout:fixed}.blank-checklist-table th,.blank-checklist-table td{white-space:normal;vertical-align:top}.blank-checklist-table th:nth-child(1),.blank-checklist-table td:nth-child(1){width:5%;text-align:center}.blank-checklist-table th:nth-child(2),.blank-checklist-table td:nth-child(2){width:67%;text-align:justify;line-height:1.45;overflow-wrap:anywhere}.blank-checklist-table th:nth-child(3),.blank-checklist-table td:nth-child(3){width:13%;text-align:center}.blank-checklist-table th:nth-child(4),.blank-checklist-table td:nth-child(4){width:15%;text-align:center}.blank-checklist-table .actions{justify-content:center;flex-wrap:wrap}
@media(max-width:800px){.cards{grid-template-columns:1fr 1fr}.wrap{padding:10px}.grid{grid-template-columns:1fr}.full{grid-column:auto}}
@media print{header,.cards,.toolbar,.legend,.actions,.record-actions{display:none!important}.wrap,.panel{padding:0;box-shadow:none}.table-wrap{overflow:visible;max-height:none}table{font-size:8px;min-width:0}th,td{padding:2px}.sticky,.sticky2,.sticky3{position:static}.record-sheet{break-after:page}.record-meta{grid-template-columns:repeat(4,1fr)}}
</style></head><body>
<?php if(!$currentUser):?>
<main class="login-page"><section class="login-card"><div class="brand-lockup"><div class="brand-name">KAYNES<span>SEMICON</span></div></div><h1>🔐 Preventive Maintenance Electronic System</h1><?php if($flash):?><div class="flash"><?=e($flash)?></div><?php endif;?><form method="post"><input type="hidden" name="action" value="login"><label class="field">Employee No.<input name="employee_no" placeholder="Enter employee number" autocomplete="username" required autofocus></label><label class="field">Password<input type="password" name="password" placeholder="Enter password" autocomplete="current-password" required></label><button class="login-submit">Log In</button></form><div class="signup-area"><p>New personnel requiring access?</p><button type="button" onclick="openSignup()">Sign Up for Approval</button></div></section></main>
<div class="modal" id="signupModal"><div class="modal-box"><h2>Personnel Enrollment</h2><p>Your request will be sent to the Administrator for approval.</p><form method="post" enctype="multipart/form-data" class="grid"><input type="hidden" name="action" value="signup"><label class="field full">Name<input name="name" required></label><label class="field">Employee No.<input name="employee_no" required></label><label class="field">Email Address<input type="email" name="email" required></label><label class="field full"><span><input type="checkbox" name="email_notifications" value="1" checked> Receive PM due email notifications</span></label><label class="field">Designation<select name="designation" required><option value="">Select designation</option><option value="Technician">Technician</option><option value="Operator">Operator</option><option value="Engineer">Engineer</option><option value="Administrator">Administrator</option></select></label><label class="field full">Personnel Photo (JPG, PNG or WebP; maximum 2 MB)<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label><label class="field">Password<input type="password" name="password" minlength="8" autocomplete="new-password" required></label><label class="field">Confirm Password<input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></label><button type="button" class="btn secondary" onclick="closeModal('signupModal')">Cancel</button><button>Submit for Approval</button></form></div></div>
<script>function openSignup(){const modal=document.getElementById('signupModal');modal.querySelectorAll('input[name="email"],input[name="photo"]').forEach(input=>input.required=false);modal.classList.add('show')}function closeModal(id){document.getElementById(id).classList.remove('show')}document.querySelectorAll('.modal').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('show')}));</script>
</body></html><?php exit; endif;?>
<header><div><h1>Preventive Maintenance Electronic System</h1><p>Front of Line Equipment · PM-CAL-001 · <?=e((string)$data['year'])?></p></div><div class="account"><?php if($currentUser):?><?php if(!empty($currentUser['photo'])):?><img class="avatar" src="<?=e($currentUser['photo'])?>" alt="User photo"><?php endif;?><div class="account-info"><b><?=e($currentUser['name'])?></b><small><?=e($currentUser['employee_no'].' · '.$currentUser['designation'])?></small></div><form method="post"><input type="hidden" name="action" value="logout"><button class="btn secondary">Log Out</button></form><?php else:?><button onclick="openLogin()">Log In</button><button class="btn secondary" onclick="openSignup()">Sign Up</button><?php endif;?></div></header>
<main class="wrap">
<?php if($flash):?><div class="flash"><?=e($flash)?></div><?php endif;?>
<nav class="nav"><a class="btn <?=$view==='dashboard'?'active':'secondary'?>" href="?view=dashboard">Dashboard</a><a class="btn <?=$view==='manage'?'active':'secondary'?>" href="?view=manage"><?=($currentUser['role']??'')==='admin'?'Manage Schedule':'PM Schedule'?></a><a class="btn <?=$view==='records'?'active':'secondary'?>" href="?view=records">PM Records</a><a class="btn <?=$view==='profile'?'active':'secondary'?>" href="?view=profile">My Profile</a><?php if($isAdministrator):?><a class="btn <?=$view==='admin'?'active':'secondary'?>" href="?view=admin">Admin Approval</a><a class="btn <?=$view==='aux'?'active':'secondary'?>" href="?view=aux">Equipment / Aux Information</a><a class="btn <?=$view==='checklists'?'active':'secondary'?>" href="?view=checklists">PM Checklists</a><a class="btn kpi-menu <?=$view==='kpi'?'active':''?>" href="?view=kpi">PM KPI</a><?php endif;?></nav>
<?php if($view==='dashboard' && ($currentUser['role']??'')==='admin'):?>
<section class="cards">
 <div class="card"><span>Selected Work Week</span><b>WW<?=str_pad((string)$selectedWeek,2,'0',STR_PAD_LEFT)?></b></div>
 <div class="card"><span>Open PM Dues</span><b style="color:var(--red)"><?=$dueThisWeek?></b></div>
 <div class="card"><span>Completed PM</span><b style="color:var(--green)"><?=$completedThisWeek?></b></div>
 <div class="card"><span>Overdue</span><b style="color:var(--red)"><?=$selectedOverdue?></b></div>
</section>
<?php endif;?>
<?php if($view==='dashboard'):?>
<section class="panel">
 <div class="toolbar"><div><h2 style="margin:0 0 4px">PM Due List — WW<?=str_pad((string)$selectedWeek,2,'0',STR_PAD_LEFT)?></h2><span><?=e($selectedWeekStart->format('M d'))?>–<?=e($selectedWeekEnd->format('M d, Y'))?></span></div><form method="get" style="margin-left:auto"><input type="hidden" name="view" value="dashboard"><select name="ww" onchange="this.form.submit()" aria-label="Select Work Week"><?php for($ww=1;$ww<=53;$ww++):$wwStart=(new DateTimeImmutable())->setISODate((int)$data['year'],$ww);?><option value="<?=$ww?>" <?=$ww===$selectedWeek?'selected':''?>>WW<?=str_pad((string)$ww,2,'0',STR_PAD_LEFT)?> · <?=e($wwStart->format('M d'))?>–<?=e($wwStart->modify('+6 days')->format('M d'))?></option><?php endfor;?></select></form><input id="dueSearch" class="search" placeholder="Search equipment name..."><select id="equipmentFilter" aria-label="Filter by Equipment ID"><option value="">All Equipment IDs</option><?php foreach($data['equipment'] as $filterEq):?><option value="<?=e(strtolower($filterEq['code']))?>"><?=e($filterEq['code'])?></option><?php endforeach;?></select><button type="button" id="soundAlarmButton" class="btn secondary" onclick="toggleOverdueAlarm()">Due Alarm ON</button></div>
 <div class="table-wrap" style="max-height:none"><table class="due-table" id="dueTable"><thead><tr><th>No.</th><th>Machine</th><th>Equipment ID</th><th>PM Due Date</th><th>Frequency Level</th><th>Status</th><th>Action</th></tr></thead><tbody>
 <?php if(!$dueItems):?><tr><td colspan="7" style="text-align:center;padding:28px">No PM is scheduled for this week.</td></tr><?php endif;?>
 <?php foreach($dueItems as $i=>$item):$daysRemaining=(int)(new DateTimeImmutable(date('Y-m-d')))->diff(new DateTimeImmutable($item['due_date']))->format('%r%a');$alarmClass=$daysRemaining<=0?'pm-warning-row':($daysRemaining<=2?'pm-upcoming-row':'');$statusText=$daysRemaining<0?'Overdue':($daysRemaining===0?'Due Today':($daysRemaining<=2?'Due in '.$daysRemaining.' Day'.($daysRemaining===1?'':'s'):'Scheduled'));$statusClass=$daysRemaining<0?'status-overdue':($daysRemaining<=2?'status-warning':'status-due');?>
 <tr class="<?=$alarmClass?>" data-due-alarm="<?=$daysRemaining<=0?'1':'0'?>" data-code="<?=e(strtolower($item['code']))?>" data-search="<?=e(strtolower($item['name'].' '.$item['code'].' '.frequencyName($item['frequency']).' '.$item['due_date']))?>"><td><?=$i+1?></td><td><b><?=e($item['name'])?></b></td><td><?=e($item['code'])?></td><td><b><?=e((new DateTimeImmutable($item['due_date']))->format('d/m/Y'))?></b></td><td><span class="badge f-<?=e(frequencyCode($item['frequency']))?>"><?=e(frequencyCode($item['frequency']))?> – <?=e(frequencyName($item['frequency']))?></span></td><td><span class="badge <?=$statusClass?> <?=($daysRemaining>0&&$daysRemaining<=2)?'status-alarm':''?>"><?=e($statusText)?></span></td><td><?php if($currentUser):?><button onclick='openCompletion(<?=$item['id']?>,<?=$item['week']?>,<?=json_encode($item['frequency'],JSON_HEX_APOS|JSON_HEX_QUOT)?>,<?=json_encode($item['name'],JSON_HEX_APOS|JSON_HEX_QUOT)?>,<?=json_encode($item['code'],JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>Mark Complete</button><?php else:?><button class="btn secondary" onclick="openLogin()">Log In to Complete</button><?php endif;?></td></tr>
 <?php endforeach;?></tbody></table></div>
</section>
<?php elseif($view==='manage'):?>
<section class="panel">
 <div class="toolbar">
  <input id="search" class="search" placeholder="Search equipment name...">
  <select id="equipmentFilter" aria-label="Filter by Equipment ID"><option value="">All Equipment IDs</option><?php foreach($data['equipment'] as $filterEq):?><option value="<?=e(strtolower($filterEq['code']))?>"><?=e($filterEq['code'])?></option><?php endforeach;?></select>
  <?php if(($currentUser['role']??'')==='admin'):?>
  <button onclick="openEquipment()">+ Add Equipment</button>
  <?php endif;?>
  <button class="btn secondary" onclick="window.print()">Print / Save PDF</button>
  <button class="btn secondary" onclick="exportCsv()">Export CSV</button>
  <?php if(($currentUser['role']??'')==='admin'):?>
  <form method="post" onsubmit="return confirm('Restore the original reference schedule? Completion logs and changes will be removed.')" style="margin-left:auto"><input type="hidden" name="action" value="reset_data"><button class="btn danger">Reset</button></form>
  <?php else:?><span class="badge f-M" style="margin-left:auto">Read-only schedule</span><?php endif;?>
 </div>
 <div class="legend"><span><i class="dot f-W"></i>W – Weekly</span><span><i class="dot f-M"></i>M – Monthly</span><span><i class="dot f-Q"></i>Q – Quarterly</span><span><i class="dot f-H"></i>H – Semi-annual</span><span><i class="dot f-A"></i>A – Annual</span><span>Green outline – Completed</span><span>Red underline – Overdue</span></div>
 <div class="table-wrap"><table id="scheduleTable" class="schedule-calendar"><thead>
  <tr><th class="sticky calendar-fixed" rowspan="3">No.</th><th class="sticky2 calendar-fixed" rowspan="3">Equipment Name</th><th class="sticky3 calendar-fixed" rowspan="3">Equipment ID</th><?php foreach($calendarQuarters as $quarter):?><th class="calendar-quarter" colspan="<?=$quarter['span']?>"><?=e($quarter['label'])?></th><?php endforeach;?><?php if(($currentUser['role']??'')==='admin'):?><th class="calendar-fixed" rowspan="3">Actions</th><?php endif;?></tr>
  <tr><?php foreach($calendarMonths as $month):?><th class="calendar-month" colspan="<?=$month['span']?>"><?=e($month['label'])?></th><?php endforeach;?></tr>
  <tr><?php foreach($calendarWeeks as $calendar):$w=$calendar['week'];$workWeekEnd=$calendar['start']->modify('+5 days');?><th class="calendar-week <?=$w===$currentWeek?'today':''?>" title="WW<?=str_pad((string)$w,2,'0',STR_PAD_LEFT)?> working dates <?=e($calendar['start']->format('d M Y'))?> to <?=e($workWeekEnd->format('d M Y'))?>"><?=e(substr((string)$data['year'],-2).str_pad((string)$w,2,'0',STR_PAD_LEFT))?><small><?=e($calendar['start']->format('d'))?>–<?=e($workWeekEnd->format('d'))?></small></th><?php endforeach;?></tr>
 </thead><tbody>
 <?php foreach($data['equipment'] as $index=>$eq):?>
  <tr data-code="<?=e(strtolower($eq['code']))?>" data-search="<?=e(strtolower($eq['name'].' '.$eq['code']))?>"><td class="sticky"><?=$index+1?></td><td class="sticky2"><?=e($eq['name'])?></td><td class="sticky3"><?=e($eq['code'])?></td>
  <?php foreach($eq['schedule'] as $i=>$freq):$week=$i+1;$key=$eq['id'].'-'.$week;$isDone=isset($completed[$key]);$isOverdue=$freq!==''&&!$isDone&&$week<$currentWeek&&(int)$data['year']<=(int)date('Y');?>
   <?php $cellDueDate=(string)($eq['due_dates'][$i]??'');if($cellDueDate!=='')$cellDueDate=advanceToPreviousScheduleDay(new DateTimeImmutable($cellDueDate))->format('Y-m-d');$cellDateLabel=$cellDueDate!==''?(new DateTimeImmutable($cellDueDate))->format('d'):'';$cellTitle=frequencyName($freq).($cellDueDate!==''?' · Perform on '.(new DateTimeImmutable($cellDueDate))->format('d/m/Y'):'');$displayFreq=frequencyCode($freq);?><td><?php if(($currentUser['role']??'')==='admin'):?><button class="freq f-<?=$displayFreq?:'none'?> <?=$isDone?'done':''?> <?=$isOverdue?'overdue':''?>" data-id="<?=$eq['id']?>" data-week="<?=$week?>" data-freq="<?=e($freq)?>" data-code="<?=e($eq['code'])?>" data-name="<?=e($eq['name'])?>" title="<?=e($cellTitle)?>"><span class="freq-code"><?=e($displayFreq?:'+')?></span><small class="freq-date"><?=e($cellDateLabel)?></small></button><?php else:?><span class="freq f-<?=$displayFreq?:'none'?> <?=$isDone?'done':''?> <?=$isOverdue?'overdue':''?>" title="<?=e($cellTitle)?>"><span class="freq-code"><?=e($displayFreq?:'–')?></span><small class="freq-date"><?=e($cellDateLabel)?></small></span><?php endif;?></td>
  <?php endforeach;?>
  <?php if(($currentUser['role']??'')==='admin'):?><td><div class="actions"><button class="small" onclick='openEquipment(<?=json_encode($eq,JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>Edit</button><form method="post" onsubmit="return confirm('Delete this equipment?')"><input type="hidden" name="action" value="delete_equipment"><input type="hidden" name="id" value="<?=$eq['id']?>"><button class="small btn danger">Delete</button></form></div></td><?php endif;?></tr>
 <?php endforeach;?></tbody></table></div>
</section>
<?php elseif($view==='records'):?>
<section class="panel">
 <h2 style="margin-top:0">Machine PM Records</h2>
 <form method="get" class="toolbar"><input type="hidden" name="view" value="records"><label for="recordEquipment"><b>Equipment ID</b></label><select id="recordEquipment" name="equipment_id" required><option value="">Select Equipment ID</option><?php foreach($data['equipment'] as $filterEq):?><option value="<?=$filterEq['id']?>" <?=$recordEquipmentId===(int)$filterEq['id']?'selected':''?>><?=e($filterEq['code'].' — '.$filterEq['name'])?></option><?php endforeach;?></select><label for="recordWorkWeek"><b>Work Week (WW)</b></label><select id="recordWorkWeek" name="record_ww" required><option value="">Select Work Week</option><?php for($recordWeekOption=1;$recordWeekOption<=52;$recordWeekOption++):$recordWeekStart=(new DateTimeImmutable())->setISODate((int)$data['year'],$recordWeekOption);$recordWeekEnd=$recordWeekStart->modify('+5 days');?><option value="<?=$recordWeekOption?>" <?=$recordWorkWeek===$recordWeekOption?'selected':''?>>WW<?=str_pad((string)$recordWeekOption,2,'0',STR_PAD_LEFT)?> · <?=e($recordWeekStart->format('M d'))?>–<?=e($recordWeekEnd->format('M d'))?></option><?php endfor;?></select><button>Search PM Records</button><?php if($recordEquipment && $recordWorkWeek>0 && $recordLogs):?><button type="button" class="btn secondary" onclick="printAllRecords()">Export Filtered PDF</button><?php endif;?></form>
 <?php if(!$recordSearchSubmitted):?><p>Select an Equipment ID and Work Week (WW) to display its completed PM record and checklist.</p>
 <?php elseif($recordEquipmentId<=0 || $recordWorkWeek<=0):?><div class="flash">Please select both Equipment ID and Work Week (WW).</div>
 <?php elseif(!$recordEquipment):?><div class="flash">Please select a valid Equipment ID.</div>
 <?php elseif(!$recordLogs):?><div class="flash">No completed PM record was found for <?=e($recordEquipment['code'])?> in WW<?=str_pad((string)$recordWorkWeek,2,'0',STR_PAD_LEFT)?>.</div>
 <?php else:?><p><b><?=e($recordEquipment['code'])?></b> — <?=e($recordEquipment['name'])?> · WW<?=str_pad((string)$recordWorkWeek,2,'0',STR_PAD_LEFT)?> · <?=count($recordLogs)?> completed record(s)</p>
 <div id="recordResults">
 <?php foreach($recordLogs as $recordNo=>$log):$checklist=is_array($log['checklist']??null)?$log['checklist']:[];$sheetId='recordSheet'.$recordNo;?>
  <article class="record-sheet" id="<?=e($sheetId)?>" style="border:1px solid #dfd2d3;border-radius:9px;padding:16px;margin:14px 0;background:#fff">
   <div class="toolbar record-actions"><div><h3 style="margin:0 0 5px"><?=e($recordEquipment['code'])?> — PM Completion Log Sheet</h3><span>WW<?=str_pad((string)($log['week']??''),2,'0',STR_PAD_LEFT)?> · <?=e(frequencyName((string)($log['frequency']??'')))?></span></div><button type="button" class="btn" style="margin-left:auto" onclick='openCallback(<?=json_encode(['record_index'=>(int)$log['_record_index'],'equipment_id'=>(int)$recordEquipment['id'],'equipment_code'=>$recordEquipment['code'],'equipment_name'=>$recordEquipment['name'],'week'=>(int)($log['week']??0),'frequency'=>(string)($log['frequency']??''),'checklist'=>$checklist,'last_callback_action'=>(string)($log['last_callback_action']??'')],JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>Callback</button><button type="button" class="btn secondary" onclick="printRecord('<?=e($sheetId)?>')">Export PDF</button></div>
   <div class="record-meta"><div><b>Machine</b><br><?=e($recordEquipment['name'])?></div><div><b>Equipment ID</b><br><?=e($recordEquipment['code'])?></div><div><b>Completion Date</b><br><?=e(!empty($log['completed_on'])?(new DateTimeImmutable($log['completed_on']))->format('d/m/Y'):'—')?></div><div><b>Frequency</b><br><?=e(frequencyCode((string)($log['frequency']??'')).' – '.frequencyName((string)($log['frequency']??'')))?></div><div><b>Completed By</b><br><?=e((string)($log['technician']??'—'))?></div><div><b>Recorded (PHT)</b><br><?=e(!empty($log['created_at'])?date('d/m/Y H:i:s',strtotime($log['created_at'])):'—')?></div><div><b>Reference Document #</b><br>FF ASM 10</div><div><b>Overall Remarks</b><br><?=e((string)($log['remarks']??'—'))?></div></div>
   <?php if($checklist):?><h4>Completed Checklist</h4><div class="table-wrap" style="max-height:none"><table class="due-table record-checklist" style="min-width:650px"><thead><tr><th>No.</th><th>Check Item</th><th>Result</th><th>Remarks</th></tr></thead><tbody><?php foreach($checklist as $check):?><tr><td><?=e((string)($check['no']??''))?></td><td><b><?=e((string)($check['item']??''))?></b></td><td><span class="badge <?=($check['result']??'')==='NG'?'status-due':'status-done'?>"><?=e((string)($check['result']??''))?></span></td><td style="white-space:normal;min-width:220px"><?=e((string)($check['remarks']??''))?></td></tr><?php endforeach;?></tbody></table></div>
   <?php else:?><p><i>This completion was recorded without an equipment checklist.</i></p><?php endif;?>
   <?php $callbackHistory=is_array($log['callback_history']??null)?$log['callback_history']:[];if($callbackHistory):?><h4>Callback History</h4><div class="table-wrap" style="max-height:none"><table class="due-table" style="min-width:700px"><thead><tr><th>Callback</th><th>Date and Time (PHT)</th><th>Updated By</th><th>Action Performed / Remarks</th></tr></thead><tbody><?php foreach($callbackHistory as $callback):?><tr><td>#<?=e((string)($callback['callback_no']??''))?></td><td><?=e(!empty($callback['updated_at'])?date('d/m/Y H:i:s',strtotime($callback['updated_at'])):'—')?></td><td><?=e((string)($callback['updated_by']??'—'))?></td><td style="white-space:normal;min-width:300px"><?=e((string)($callback['action_remarks']??'—'))?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
  </article>
 <?php endforeach;?></div><?php endif;?>
</section>
<?php elseif($view==='aux'):?>
<section class="panel">
 <div class="toolbar"><div><h2 style="margin:0 0 4px">Equipment / Aux Information</h2><span>Administrator-only master list imported from Equipment FinAL Machine id.xls</span></div><button type="button" style="margin-left:auto" onclick="openAuxInformation()">+ Add Information</button></div>
 <div class="toolbar"><label>Machine ID <select id="auxMachineIdFilter"><option value="">All Machine IDs</option><?php foreach($auxMachineIds as $option):?><option value="<?=e(strtolower($option))?>"><?=e($option)?></option><?php endforeach;?></select></label><label>Category <select id="auxCategoryFilter"><option value="">All Categories</option><?php foreach($auxCategories as $option):?><option value="<?=e(strtolower($option))?>"><?=e($option)?></option><?php endforeach;?></select></label><label>Machine Name <select id="auxMachineNameFilter"><option value="">All Machine Names</option><?php foreach($auxMachineNames as $option):?><option value="<?=e(strtolower($option))?>"><?=e($option)?></option><?php endforeach;?></select></label><button type="button" class="btn secondary" onclick="resetAuxFilters()">Reset Filters</button><button type="button" class="btn secondary" onclick="printAuxInformation()">Export PDF</button><button type="button" class="btn secondary" onclick="exportAuxExcel()">Export Excel</button></div>
 <div class="table-wrap" style="max-height:72vh"><table class="due-table" id="auxTable" style="min-width:1700px"><thead><tr><th>No.</th><?php foreach($data['aux_headers'] as $header):?><th><?=e((string)$header)?></th><?php endforeach;?><th>Action</th></tr></thead><tbody>
 <?php foreach($data['aux_information'] as $index=>$auxRow):$values=normalizeAuxInformationRow(is_array($auxRow['values']??null)?$auxRow['values']:[]);?><tr data-machine-id="<?=e(strtolower($values[0]))?>" data-machine-name="<?=e(strtolower($values[1]))?>" data-category="<?=e(strtolower($values[4]))?>"><td><?=$index+1?></td><?php foreach($values as $value):?><td><?=e((string)$value)?></td><?php endforeach;?><td><div class="actions"><button type="button" class="small" onclick='openAuxInformation(<?=json_encode(['id'=>(int)$auxRow['id'],'values'=>$values],JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>Edit</button><form method="post" onsubmit="return confirm('Delete this Equipment / Aux Information record?')"><input type="hidden" name="action" value="delete_aux_information"><input type="hidden" name="id" value="<?=(int)$auxRow['id']?>"><button class="small btn danger">Delete</button></form></div></td></tr><?php endforeach;?>
 </tbody></table></div>
</section>
<?php elseif($view==='checklists'):?>
<section class="panel">
 <h2 style="margin-top:0">Blank PM Checklist Library</h2><p>Create one checklist per Model Number. Every Machine ID with that model will automatically use the same checklist.</p>
 <form method="get" class="toolbar"><input type="hidden" name="view" value="checklists"><label><b>View Checklist by Model Number</b> <select name="model_number" onchange="this.form.submit()"><option value="">Select Model Number</option><?php foreach($auxModelNumbers as $option):?><option value="<?=e($option)?>" <?=$checklistModelNumber===$option?'selected':''?>><?=e($option)?></option><?php endforeach;?></select></label></form>
 <form method="post" class="toolbar"><input type="hidden" name="action" value="create_checklist_template"><label><b>Model Number</b> <select name="model_number" required><option value="">Select enrolled Model Number</option><?php foreach($auxModelNumbers as $option):?><option value="<?=e($option)?>"><?=e($option)?></option><?php endforeach;?></select></label><button>Create Model Checklist</button></form>
 <?php $templatesToShow=$selectedChecklistTemplate?[$selectedChecklistTemplate]:[]; if($checklistModelNumber==='' ):?><div class="flash">Select a Model Number to display its PM checklist.</div><?php elseif(!$templatesToShow):?><div class="flash">No checklist has been created for model <?=e($checklistModelNumber)?>.</div><?php endif;?>
 <?php foreach($templatesToShow as $template):?><article class="record-sheet checklist-template" id="template<?=(int)$template['id']?>" style="border:1px solid #dfd2d3;border-radius:9px;padding:16px;margin:14px 0"><div class="toolbar"><div><h3 style="margin:0">Model: <?=e($template['model_number'])?></h3><small>Shared Blank PM Checklist · <?=count($template['items']??[])?> item(s)</small></div><button type="button" style="margin-left:auto" onclick='openChecklistItem(<?=(int)$template['id']?>,<?=json_encode($template['model_number'],JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>+ Add Check Item</button><button type="button" class="btn secondary" onclick="printBlankChecklist('template<?=(int)$template['id']?>')">Export Blank PDF</button><form method="post" onsubmit="return confirm('Delete this entire checklist template?')"><input type="hidden" name="action" value="delete_checklist_template"><input type="hidden" name="template_id" value="<?=(int)$template['id']?>"><button class="btn danger">Delete Checklist</button></form></div><div class="table-wrap" style="max-height:none"><table class="due-table blank-checklist-table"><colgroup><col style="width:5%"><col style="width:67%"><col style="width:13%"><col style="width:15%"></colgroup><thead><tr><th>No.</th><th>Check Item</th><th>Applicable Frequencies</th><th>Action</th></tr></thead><tbody><?php foreach(($template['items']??[]) as $itemNo=>$item):?><tr><td><?=$itemNo+1?></td><td><b><?=e($item['check_item'])?></b></td><td><?=e(implode(', ',$item['frequencies']??[]))?></td><td><div class="actions"><button type="button" class="small" onclick='openChecklistItem(<?=(int)$template['id']?>,<?=json_encode($template['model_number'],JSON_HEX_APOS|JSON_HEX_QUOT)?>,<?=json_encode($item,JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>Edit</button><form method="post" onsubmit="return confirm('Delete this checklist item?')"><input type="hidden" name="action" value="delete_checklist_item"><input type="hidden" name="template_id" value="<?=(int)$template['id']?>"><input type="hidden" name="item_id" value="<?=(int)$item['id']?>"><button class="small btn danger">Delete</button></form></div></td></tr><?php endforeach;?><?php if(empty($template['items'])):?><tr><td colspan="4">Blank checklist — add the first check item.</td></tr><?php endif;?></tbody></table></div></article><?php endforeach;?>
</section>
<?php elseif($view==='kpi'):?>
<section class="panel">
 <div class="toolbar"><div><h2 style="margin:0 0 4px">PM KPI</h2><span>Administrator-only performance summary · Goal: ZERO overdue and ZERO callback</span></div><form method="get" class="toolbar" style="margin-left:auto;margin-bottom:0"><input type="hidden" name="view" value="kpi"><label><b>Frequency</b> <select name="kpi_frequency"><option value="" <?=$kpiFrequency===''?'selected':''?>>All Frequencies</option><?php foreach(['W'=>'Weekly','M'=>'Monthly','Q'=>'Quarterly','H'=>'Semi-annual','A'=>'Annual'] as $code=>$label):?><option value="<?=$code?>" <?=$kpiFrequency===$code?'selected':''?>><?=$code?> – <?=$label?></option><?php endforeach;?></select></label><label><b>Start WW</b> <select name="kpi_start_ww"><?php for($kpiWeekOption=1;$kpiWeekOption<=52;$kpiWeekOption++):$kpiWeekStart=(new DateTimeImmutable())->setISODate((int)$data['year'],$kpiWeekOption);?><option value="<?=$kpiWeekOption?>" <?=$kpiStartWeek===$kpiWeekOption?'selected':''?>>WW<?=str_pad((string)$kpiWeekOption,2,'0',STR_PAD_LEFT)?> · <?=e($kpiWeekStart->format('M d'))?></option><?php endfor;?></select></label><label><b>Trend Monitoring</b> <select name="kpi_trend_period"><option value="monthly" <?=$kpiTrendPeriod==='monthly'?'selected':''?>>Monthly</option><option value="quarterly" <?=$kpiTrendPeriod==='quarterly'?'selected':''?>>Quarterly</option></select></label><button>Apply Filter</button><a class="btn secondary" href="?view=kpi">Reset</a></form></div>
</section>
<section class="cards" style="margin-top:16px">
 <div class="card"><span>Total Overdue PM</span><b style="color:var(--red)"><?=count($kpiOverdueItems)?></b><small>Goal: 0</small></div>
 <div class="card"><span>Total Callbacks</span><b style="color:#e9820b"><?=count($kpiCallbackItems)?></b><small>Goal: 0</small></div>
</section>
<section class="panel" style="margin-top:16px">
 <h3 style="margin:0 0 4px"><?=e(ucfirst($kpiTrendPeriod))?> Trend — <?=e((string)$data['year'])?></h3><p style="margin-top:0">Monitoring coverage starts from WW<?=str_pad((string)$kpiStartWeek,2,'0',STR_PAD_LEFT)?> (<?=e($kpiStartDate->format('M d, Y'))?>).</p>
 <div style="overflow-x:auto"><svg viewBox="0 0 1020 285" role="img" aria-label="<?=e(ucfirst($kpiTrendPeriod))?> overdue and callback trend with zero goal" style="display:block;width:100%;min-width:760px;height:auto;background:#fff;border:1px solid #dfd2d3;border-radius:8px">
  <?php for($grid=0;$grid<=4;$grid++):$gridY=50+($grid*46.25);$gridValue=(int)round($kpiTrendMax*(1-$grid/4));?><line x1="55" y1="<?=$gridY?>" x2="955" y2="<?=$gridY?>" stroke="#eadfe0"/><text x="43" y="<?=$gridY+4?>" text-anchor="end" font-size="11" fill="#705b5d"><?=$gridValue?></text><?php endfor;?>
  <line x1="55" y1="235" x2="955" y2="235" stroke="#16814d" stroke-width="3" stroke-dasharray="7 5"/>
  <polyline points="<?=e($kpiChartPoints($kpiOverdueSeries,$kpiTrendMax))?>" fill="none" stroke="#b3242d" stroke-width="4"/>
  <polyline points="<?=e($kpiChartPoints($kpiCallbackSeries,$kpiTrendMax))?>" fill="none" stroke="#e9820b" stroke-width="4"/>
  <?php foreach($kpiTrendLabels as $trendIndex=>$trendLabel):$x=$kpiChartX($trendIndex,count($kpiTrendLabels));?><circle cx="<?=$x?>" cy="<?=235-(($kpiOverdueSeries[$trendIndex]/$kpiTrendMax)*185)?>" r="4" fill="#b3242d"><title><?=e($trendLabel)?> overdue: <?=$kpiOverdueSeries[$trendIndex]?></title></circle><circle cx="<?=$x?>" cy="<?=235-(($kpiCallbackSeries[$trendIndex]/$kpiTrendMax)*185)?>" r="4" fill="#e9820b"><title><?=e($trendLabel)?> callbacks: <?=$kpiCallbackSeries[$trendIndex]?></title></circle><text x="<?=$x?>" y="258" text-anchor="middle" font-size="11" fill="#4f3e40"><?=e($trendLabel)?></text><?php endforeach;?>
  <text x="55" y="25" font-size="12" font-weight="700" fill="#b3242d">● Overdue</text><text x="145" y="25" font-size="12" font-weight="700" fill="#e9820b">● Callback</text><text x="245" y="25" font-size="12" font-weight="700" fill="#16814d">– – Goal: Zero</text>
 </svg></div>
</section>
<section class="panel" style="margin-top:16px"><h3 style="margin-top:0">Overdue PM Details</h3><div class="table-wrap" style="max-height:420px"><table class="due-table" style="min-width:850px"><thead><tr><th>No.</th><th>Machine</th><th>Equipment ID</th><th>Work Week</th><th>Due Date</th><th>Frequency</th></tr></thead><tbody><?php if(!$kpiOverdueItems):?><tr><td colspan="6">No overdue PM found for this frequency.</td></tr><?php endif;?><?php foreach($kpiOverdueItems as $i=>$item):?><tr><td><?=$i+1?></td><td style="text-align:left;white-space:normal"><?=e($item['name'])?></td><td><?=e($item['code'])?></td><td>WW<?=str_pad((string)$item['week'],2,'0',STR_PAD_LEFT)?></td><td><?=e(date('d/m/Y',strtotime($item['due_date'])))?></td><td><span class="badge f-<?=e($item['frequency'])?>"><?=e($item['frequency'].' – '.frequencyName($item['frequency']))?></span></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="panel" style="margin-top:16px"><h3 style="margin-top:0">Callback Details</h3><div class="table-wrap" style="max-height:420px"><table class="due-table" style="min-width:1150px"><thead><tr><th>No.</th><th>Machine</th><th>Equipment ID</th><th>Work Week</th><th>Frequency</th><th>Callback</th><th>Date &amp; Time (PHT)</th><th>Updated By</th><th>Action / Remarks</th></tr></thead><tbody><?php if(!$kpiCallbackItems):?><tr><td colspan="9">No callback found for this frequency.</td></tr><?php endif;?><?php foreach($kpiCallbackItems as $i=>$item):?><tr><td><?=$i+1?></td><td style="text-align:left;white-space:normal"><?=e($item['name'])?></td><td><?=e($item['code'])?></td><td>WW<?=str_pad((string)$item['week'],2,'0',STR_PAD_LEFT)?></td><td><span class="badge f-<?=e($item['frequency'])?>"><?=e($item['frequency'].' – '.frequencyName($item['frequency']))?></span></td><td>#<?=e((string)$item['callback_no'])?></td><td><?=e($item['updated_at']!==''?date('d/m/Y H:i:s',strtotime($item['updated_at'])):'—')?></td><td><?=e($item['updated_by']?:'—')?></td><td style="text-align:left;white-space:normal;min-width:280px"><?=e($item['remarks']?:'—')?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php elseif($view==='profile'):?>
<section class="panel"><h2>My Profile</h2><div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap"><?php if(!empty($currentUser['photo'])):?><img class="approval-photo" style="width:120px;height:120px" src="<?=e($currentUser['photo'])?>" alt="Profile photo"><?php endif;?><form method="post" enctype="multipart/form-data" class="grid" style="max-width:720px;flex:1"><input type="hidden" name="action" value="update_profile"><label class="field">Name<input value="<?=e($currentUser['name'])?>" readonly></label><label class="field">Employee No.<input value="<?=e($currentUser['employee_no'])?>" readonly></label><label class="field">Designation<input value="<?=e($currentUser['designation'])?>" readonly></label><label class="field">Email Address<input type="email" name="email" value="<?=e((string)($currentUser['email']??''))?>" required></label><label class="field full"><span><input type="checkbox" name="email_notifications" value="1" <?=!empty($currentUser['email_notifications'])?'checked':''?>> Receive PM due email notifications</span></label><label class="field full">Upload New Profile Photo<input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp"><small>Leave blank to keep the current photo. Maximum 2 MB.</small></label><span></span><button>Update Profile</button></form></div></section>
<section class="panel" style="margin-top:16px"><h2>Change My Password</h2><form method="post" class="grid" style="max-width:720px"><input type="hidden" name="action" value="change_own_password"><label class="field full">Current Password<input type="password" name="current_password" required></label><label class="field">New Password<input type="password" name="new_password" minlength="8" required></label><label class="field">Confirm New Password<input type="password" name="confirm_password" minlength="8" required></label><span></span><button>Change Password</button></form></section>
<?php else:?>
<section class="panel"><h2>PM Email Notifications</h2><p>Automatic due-list emails are sent to approved employees who have a valid email address and enabled notifications, when PM items are due within 2 days or overdue.</p><div style="display:flex;gap:12px;flex-wrap:wrap"><form method="post"><input type="hidden" name="action" value="send_test_email"><button class="btn secondary">Send SMTP Test Email to Admin</button></form><form method="post"><input type="hidden" name="action" value="send_due_email"><button>Send Due Email Now</button></form></div><small>SMTP test recipient: <?=e((string)($currentUser['email']??'No Admin email set — update My Profile first'))?>. The SMTP test works even when there are no due PM items.</small></section>
<section class="panel" style="margin-top:16px"><h2>User Enrollment Approval and Accounts</h2><div class="table-wrap" style="max-height:none"><table class="due-table"><thead><tr><th>Photo</th><th>Name</th><th>Employee No.</th><th>Email</th><th>Notify</th><th>Designation</th><th>Requested</th><th>Status</th><th>Approval / Password Reset</th></tr></thead><tbody>
<?php foreach($data['users'] as $user):if((int)$user['id']===(int)$currentUser['id'])continue;?><tr><td><?php if(!empty($user['photo'])):?><img class="approval-photo" src="<?=e($user['photo'])?>" alt="<?=e($user['name'])?> photo"><?php else:?>No photo<?php endif;?></td><td><?=e($user['name'])?></td><td><?=e($user['employee_no'])?></td><td><?=e((string)($user['email']??''))?></td><td><?=!empty($user['email_notifications'])?'Yes':'No'?></td><td><?=e($user['designation'])?></td><td><?=e(date('d/m/Y H:i',strtotime($user['created_at'])))?></td><td><span class="badge <?=($user['status']==='approved'?'status-done':($user['status']==='pending'?'f-H':'status-due'))?>"><?=e(ucfirst($user['status']))?></span></td><td><?php if($user['status']==='pending'):?><div class="actions"><form method="post"><input type="hidden" name="action" value="approve_user"><input type="hidden" name="user_id" value="<?=$user['id']?>"><button>Approve</button></form><form method="post"><input type="hidden" name="action" value="reject_user"><input type="hidden" name="user_id" value="<?=$user['id']?>"><button class="btn danger">Reject</button></form></div><?php elseif($user['status']==='approved'):?><form method="post" class="actions" onsubmit="return confirm('Reset this user password?')"><input type="hidden" name="action" value="reset_user_password"><input type="hidden" name="user_id" value="<?=$user['id']?>"><input type="password" name="new_password" minlength="8" placeholder="New password" required><button>Reset Password</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div></section>
<section class="panel" style="margin-top:16px"><h2>Administrator Password</h2><form method="post" class="grid" style="max-width:720px"><input type="hidden" name="action" value="change_admin_password"><label class="field">Current Password<input type="password" name="current_password" required></label><span></span><label class="field">New Password<input type="password" name="new_password" minlength="8" required></label><label class="field">Confirm New Password<input type="password" name="confirm_password" minlength="8" required></label><span></span><button>Change Password</button></form></section>
<section class="panel" style="margin-top:16px"><h2>Latest Transaction Audit Log</h2><div class="table-wrap" style="max-height:420px"><table class="due-table"><thead><tr><th>Date & Time (PHT)</th><th>Employee</th><th>Name</th><th>Action</th><th>Details</th></tr></thead><tbody><?php foreach(array_slice(array_reverse($data['audit_logs']),0,50) as $audit):?><tr><td><?=e(date('d/m/Y H:i:s',strtotime($audit['timestamp'])))?></td><td><?=e((string)$audit['employee_no'])?></td><td><?=e((string)$audit['name'])?></td><td><?=e((string)$audit['action'])?></td><td><?=e((string)$audit['details'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php endif;?></main>

<?php if(($currentUser['role']??'')==='admin'):?><div class="modal" id="auxModal"><div class="modal-box" style="width:min(900px,96vw);max-height:92vh;overflow:auto"><h2 id="auxTitle">Add Equipment / Aux Information</h2><form method="post" class="grid"><input type="hidden" name="action" value="save_aux_information"><input type="hidden" name="id" id="auxId" value="0"><?php foreach($data['aux_headers'] as $fieldIndex=>$header):?><label class="field <?=in_array($fieldIndex,[1,3,8],true)?'full':''?>"><?=e((string)$header)?><input name="values[<?=$fieldIndex?>]" id="auxField<?=$fieldIndex?>" <?=in_array($fieldIndex,[0,4],true)?'autocomplete="off"':''?>></label><?php endforeach;?><button type="button" class="btn secondary" onclick="closeModal('auxModal')">Cancel</button><button>Save Information</button></form></div></div><?php endif;?>
<?php if(($currentUser['role']??'')==='admin'):?><div class="modal" id="checklistItemModal"><div class="modal-box" style="width:min(760px,96vw)"><h2 id="checklistItemTitle">Add Checklist Item</h2><p id="checklistModelLabel"></p><form method="post" class="grid"><input type="hidden" name="action" value="save_checklist_item"><input type="hidden" name="template_id" id="checklistTemplateId"><input type="hidden" name="item_id" id="checklistItemId" value="0"><label class="field full">Check Item<input name="check_item" id="checklistItemName" required></label><fieldset class="field full"><legend><b>Applicable Frequencies</b></legend><div class="frequency-choices"><?php foreach(['W'=>'Weekly','M'=>'Monthly','Q'=>'Quarterly','H'=>'Semi-annual','A'=>'Annual'] as $frequency=>$label):?><label><input class="checklist-frequency" type="checkbox" name="frequencies[]" value="<?=$frequency?>"> <?=frequencyCode($frequency)?> – <?=$label?></label><?php endforeach;?></div></fieldset><button type="button" class="btn secondary" onclick="closeModal('checklistItemModal')">Cancel</button><button>Save Checklist Item</button></form></div></div><?php endif;?>
<div class="modal" id="equipmentModal"><div class="modal-box"><h2 id="eqTitle">Add Equipment</h2><form method="post" class="grid"><input type="hidden" name="action" value="save_equipment"><input type="hidden" name="id" id="eqId" value="0"><label class="field full">Equipment Name<input name="name" id="eqName" required></label><label class="field full">Equipment ID<input name="code" id="eqCode" required></label><fieldset class="field full"><legend><b>Applicable PM Frequencies</b></legend><div class="frequency-choices"><label><input class="eq-frequency" type="checkbox" name="frequencies[]" value="W"> W – Weekly</label><label><input class="eq-frequency" type="checkbox" name="frequencies[]" value="M"> M – Monthly (every 4 weeks)</label><label><input class="eq-frequency" type="checkbox" name="frequencies[]" value="Q"> Q – Quarterly (every 12 weeks)</label><label><input class="eq-frequency" type="checkbox" name="frequencies[]" value="H"> H – Semi-annual (every 26 weeks)</label><label><input class="eq-frequency" type="checkbox" name="frequencies[]" value="A"> A – Annual (52-week slot)</label></div><small>Frequency codes follow the revised Excel calendar: W, M, Q, H and A. At overlaps, Annual takes priority over Semi-annual, Quarterly, Monthly and Weekly.</small></fieldset><label class="field">Enrollment / First Weekly PM Date<input type="date" name="enrollment_date" id="eqEnrollmentDate" value="<?=date('Y-m-d')?>" required></label><label class="field">Starting Work Week<select name="start_week" id="eqStartWeek" required><?php for($ww=1;$ww<=52;$ww++):?><option value="<?=$ww?>" <?=$ww===(int)date('W')?'selected':''?>>WW<?=str_pad((string)$ww,2,'0',STR_PAD_LEFT)?></option><?php endfor;?></select></label><div class="field full"><small>The date and work week stay aligned. W=1 week, M=4 weeks, Q=12 weeks, H=26 weeks, and A=52 weeks. Saturday is allowed; a Sunday due date advances to Saturday.</small></div><button type="button" class="btn secondary" onclick="closeModal('equipmentModal')">Cancel</button><button>Save and Generate 52-Week Schedule</button></form></div></div>
<div class="modal" id="loginModal"><div class="modal-box"><h2>User Login</h2><form method="post" class="grid"><input type="hidden" name="action" value="login"><label class="field full">Employee No.<input name="employee_no" autocomplete="username" required></label><label class="field full">Password<input type="password" name="password" autocomplete="current-password" required></label><button type="button" class="btn secondary" onclick="closeModal('loginModal')">Cancel</button><button>Log In</button></form></div></div>
<div class="modal" id="signupModal"><div class="modal-box"><h2>Personnel Enrollment</h2><p>Your request must be approved by the Administrator before login.</p><form method="post" enctype="multipart/form-data" class="grid"><input type="hidden" name="action" value="signup"><label class="field full">Name<input name="name" required></label><label class="field">Employee No.<input name="employee_no" required></label><label class="field">Email Address<input type="email" name="email" required></label><label class="field full"><span><input type="checkbox" name="email_notifications" value="1" checked> Receive PM due email notifications</span></label><label class="field">Designation<select name="designation" required><option value="">Select designation</option><option value="Technician">Technician</option><option value="Operator">Operator</option><option value="Engineer">Engineer</option><option value="Administrator">Administrator</option></select></label><label class="field full">Personnel Photo (JPG, PNG or WebP; maximum 2 MB)<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label><label class="field">Password<input type="password" name="password" minlength="8" autocomplete="new-password" required></label><label class="field">Confirm Password<input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></label><button type="button" class="btn secondary" onclick="closeModal('signupModal')">Cancel</button><button>Submit for Approval</button></form></div></div>
<div class="modal" id="completeModal"><div class="modal-box" style="width:min(1050px,96vw);max-height:92vh;overflow:auto"><h2>PM Completion Log Sheet</h2><p id="completionLabel"></p><form method="post" class="grid"><input type="hidden" name="action" value="complete_pm"><input type="hidden" name="return_ww" value="<?=$selectedWeek?>"><input type="hidden" name="id" id="completeId"><input type="hidden" name="week" id="completeWeek"><input type="hidden" name="frequency" id="completeFrequency"><input type="hidden" name="equipment_code" id="completeCode"><label class="field">Completion Date<input type="date" name="completed_on" value="<?=date('Y-m-d')?>" required></label><label class="field">Recorded By<input value="<?=e($currentUser?$currentUser['name'].' ('.$currentUser['employee_no'].')':'Login required')?>" readonly></label><label class="field full">Reference Document #<input name="work_order" value="FF ASM 10" readonly></label><div class="field full" id="checklistSection" style="display:none"><h3 id="checklistTitle">PM Checklist</h3><div class="table-wrap" style="max-height:430px"><table class="due-table" style="min-width:650px"><thead><tr><th>No.</th><th>Check Item</th><th>Result</th><th>Remarks</th></tr></thead><tbody id="checklistBody"></tbody></table></div><small>All displayed items are mandatory. An NG result requires remarks.</small></div><label class="field full">Overall Remarks<textarea name="remarks" rows="3"></textarea></label><button type="button" class="btn secondary" onclick="closeModal('completeModal')">Cancel</button><button>Submit PM Log Sheet</button></form></div></div>
<div class="modal" id="callbackModal"><div class="modal-box" style="width:min(1050px,96vw);max-height:92vh;overflow:auto"><h2>PM Callback Checklist</h2><p id="callbackLabel"></p><form method="post" class="grid"><input type="hidden" name="action" value="callback_pm"><input type="hidden" name="record_index" id="callbackRecordIndex"><input type="hidden" name="equipment_id" id="callbackEquipmentId"><input type="hidden" name="record_ww" id="callbackWorkWeek"><label class="field">Callback Date<input value="<?=date('d/m/Y')?>" readonly></label><label class="field">Updated By<input value="<?=e($currentUser?$currentUser['name'].' ('.$currentUser['employee_no'].')':'Login required')?>" readonly></label><label class="field full">Reference Document #<input value="FF ASM 10" readonly></label><div class="field full"><h3>Update PM Checklist</h3><div class="table-wrap" style="max-height:430px"><table class="due-table" style="min-width:650px"><thead><tr><th>No.</th><th>Check Item</th><th>Result</th><th>Remarks</th></tr></thead><tbody id="callbackChecklistBody"></tbody></table></div><small>Review every item. An NG result requires remarks.</small></div><label class="field full">Action Performed / Callback Remarks<textarea name="callback_action" id="callbackAction" rows="4" placeholder="Describe the problem found and the corrective action performed before returning the machine to production." required></textarea></label><button type="button" class="btn secondary" onclick="closeModal('callbackModal')">Cancel</button><button>Save Callback Update</button></form></div></div>
<script>
const frequencies=['','W','M','Q','H','A'];
const checklistTemplates=<?=json_encode($data['checklist_templates']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const equipmentModelMap=<?=json_encode($equipmentModelMap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const checklistPrefixModelMap=<?=json_encode(checklistPrefixModelMap(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const dueAlarmCount=document.querySelectorAll('#dueTable tbody tr[data-due-alarm="1"]').length;
const soundAlarmButton=document.getElementById('soundAlarmButton');
let overdueSoundEnabled=localStorage.getItem('pmOverdueSoundEnabled')!=='0';
let overdueAlarmTimer=null;
let alarmAudioContext=null;
function updateOverdueAlarmButton(){
 if(!soundAlarmButton)return;
 soundAlarmButton.classList.toggle('sound-alarm-on',overdueSoundEnabled&&dueAlarmCount===0);
 soundAlarmButton.classList.toggle('sound-alarm-alert',overdueSoundEnabled&&dueAlarmCount>0);
 soundAlarmButton.textContent=!overdueSoundEnabled?'Enable Due Alarm':(dueAlarmCount>0?'Mute Due Alarm ('+dueAlarmCount+')':'Due Alarm ON');
 soundAlarmButton.title=overdueSoundEnabled?'Click to mute due and overdue alarms':'Click to enable and test the laptop speaker';
}
async function playOverdueAlarm(testOnly=false){
 if(!overdueSoundEnabled||(!testOnly&&dueAlarmCount===0))return;
 const AudioCtx=window.AudioContext||window.webkitAudioContext;
 if(!AudioCtx)return;
 if(!alarmAudioContext)alarmAudioContext=new AudioCtx();
 if(alarmAudioContext.state==='suspended')await alarmAudioContext.resume();
 const start=alarmAudioContext.currentTime;
 [0,.28,.56].forEach(offset=>{
  const oscillator=alarmAudioContext.createOscillator(),gain=alarmAudioContext.createGain();
  oscillator.type='square';oscillator.frequency.setValueAtTime(880,start+offset);
  gain.gain.setValueAtTime(.0001,start+offset);
  gain.gain.exponentialRampToValueAtTime(.15,start+offset+.02);
  gain.gain.exponentialRampToValueAtTime(.0001,start+offset+.18);
  oscillator.connect(gain);gain.connect(alarmAudioContext.destination);
  oscillator.start(start+offset);oscillator.stop(start+offset+.2);
 });
}
function startOverdueAlarm(){
 if(overdueAlarmTimer)clearInterval(overdueAlarmTimer);
 overdueAlarmTimer=null;
 if(overdueSoundEnabled&&dueAlarmCount>0){playOverdueAlarm();overdueAlarmTimer=setInterval(()=>playOverdueAlarm(),30000)}
 updateOverdueAlarmButton();
}
function toggleOverdueAlarm(){
 overdueSoundEnabled=!overdueSoundEnabled;
 localStorage.setItem('pmOverdueSoundEnabled',overdueSoundEnabled?'1':'0');
 if(!overdueSoundEnabled){if(overdueAlarmTimer)clearInterval(overdueAlarmTimer);overdueAlarmTimer=null}
 else{playOverdueAlarm(true);if(dueAlarmCount>0)overdueAlarmTimer=setInterval(()=>playOverdueAlarm(),30000)}
 updateOverdueAlarmButton();
}
updateOverdueAlarmButton();
if(overdueSoundEnabled&&dueAlarmCount>0){
 const armOverdueAlarm=()=>{startOverdueAlarm();document.removeEventListener('pointerdown',armOverdueAlarm)};
 document.addEventListener('pointerdown',armOverdueAlarm);
}
document.querySelectorAll('button.freq').forEach(btn=>{
 btn.addEventListener('click',async()=>{const previous=btn.dataset.freq;const next=frequencies[(frequencies.indexOf(previous)+1)%frequencies.length];const body=new URLSearchParams({action:'set_frequency',id:btn.dataset.id,week:btn.dataset.week,frequency:next});const r=await fetch(location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':document.querySelector('meta[name=\"csrf-token\"]')?.content||''},body});if(r.ok){const result=await r.json();const dateObject=result.due_date?new Date(result.due_date+'T12:00:00'):null;const date=dateObject?dateObject.toLocaleDateString('en-GB',{day:'2-digit'}):'';const fullDate=dateObject?dateObject.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}):'';btn.classList.remove('f-'+(displayFrequencyCode(previous)||'none'));btn.dataset.freq=next;btn.classList.add('f-'+(displayFrequencyCode(next)||'none'));btn.querySelector('.freq-code').textContent=displayFrequencyCode(next)||'+';btn.querySelector('.freq-date').textContent=date;btn.title=next?(frequencyName(next)+(fullDate?' · Perform on '+fullDate:'')):'Not scheduled';}});
});
function frequencyName(f){return {W:'Weekly',M:'Monthly',Q:'Quarterly',H:'Semi-annual',A:'Annual'}[f]||'Not scheduled'}
function displayFrequencyCode(f){return f}
function checklistItemApplies(itemFrequencies,scheduledFrequency){
 const rank={W:1,M:2,Q:3,H:4,A:5},target=rank[scheduledFrequency];
 if(!target)return false;
 const applicable=(itemFrequencies||[]).map(value=>rank[value]).filter(Boolean);
 return applicable.length>0&&Math.min(...applicable)<=target;
}
function openLogin(){document.getElementById('loginModal').classList.add('show')}
function openSignup(){const modal=document.getElementById('signupModal');modal.querySelectorAll('input[name="email"],input[name="photo"]').forEach(input=>input.required=false);modal.classList.add('show')}
function openCompletion(id,week,freq,name,code){
 document.getElementById('completeId').value=id;
 document.getElementById('completeWeek').value=week;
 document.getElementById('completeFrequency').value=freq;
 document.getElementById('completeCode').value=code;
 document.getElementById('completionLabel').textContent=code+' · '+name+' · WW'+String(week).padStart(2,'0')+' · '+frequencyName(freq);
 const section=document.getElementById('checklistSection'),body=document.getElementById('checklistBody');
 body.replaceChildren();
 const normalizedCode=String(code).replace(/\s+/g,'').toUpperCase();
 const prefixMatch=Object.entries(checklistPrefixModelMap).find(([prefix])=>normalizedCode.startsWith(prefix+'-'));
 const modelNumber=(prefixMatch?prefixMatch[1]:'')||equipmentModelMap[normalizedCode.toLowerCase()]||'';
 const template=checklistTemplates.find(row=>String(row.model_number||'').toLowerCase()===String(modelNumber).toLowerCase());
 const applicable=template?(template.items||[]).filter(row=>checklistItemApplies(row.frequencies,freq)):[];
 if(template&&applicable.length){
  document.getElementById('checklistTitle').textContent=code+' · '+modelNumber+' – '+frequencyName(freq)+' Checklist';
  applicable.forEach((row,index)=>{
   const number=row.id;
   const tr=document.createElement('tr');
   const no=document.createElement('td');no.textContent=index+1;
   const item=document.createElement('td');const strong=document.createElement('b');strong.textContent=row.check_item;item.appendChild(strong);
   const resultCell=document.createElement('td');const select=document.createElement('select');select.name='check_result['+number+']';select.required=true;
   [['','Select'],['OK','OK'],['NG','NG'],['N/A','N/A']].forEach(([value,label])=>{const option=document.createElement('option');option.value=value;option.textContent=label;select.appendChild(option)});
   const remarksCell=document.createElement('td');const remarks=document.createElement('input');remarks.name='check_remarks['+number+']';remarks.placeholder='Required for NG';
   select.addEventListener('change',()=>{remarks.required=select.value==='NG'});resultCell.appendChild(select);remarksCell.appendChild(remarks);
   tr.append(no,item,resultCell,remarksCell);body.appendChild(tr);
  });
  section.style.display='block';
 }else section.style.display='none';
 document.getElementById('completeModal').classList.add('show');
}
function openCallback(record){
 document.getElementById('callbackRecordIndex').value=record.record_index;
 document.getElementById('callbackEquipmentId').value=record.equipment_id;
 document.getElementById('callbackWorkWeek').value=record.week;
 document.getElementById('callbackLabel').textContent=record.equipment_code+' · '+record.equipment_name+' · WW'+String(record.week).padStart(2,'0')+' · '+frequencyName(record.frequency);
 document.getElementById('callbackAction').value='';
 const body=document.getElementById('callbackChecklistBody');body.replaceChildren();
 (record.checklist||[]).forEach((row,index)=>{
  const tr=document.createElement('tr');
  const no=document.createElement('td');no.textContent=row.no||index+1;
  const item=document.createElement('td');const strong=document.createElement('b');strong.textContent=row.item||'';item.appendChild(strong);
  const resultCell=document.createElement('td');const select=document.createElement('select');select.name='callback_result['+index+']';select.required=true;
  [['OK','OK'],['NG','NG'],['N/A','N/A']].forEach(([value,label])=>{const option=document.createElement('option');option.value=value;option.textContent=label;option.selected=value===row.result;select.appendChild(option)});
  const remarksCell=document.createElement('td');const remarks=document.createElement('input');remarks.name='callback_remarks['+index+']';remarks.value=row.remarks||'';remarks.placeholder='Required for NG';remarks.required=select.value==='NG';
  select.addEventListener('change',()=>{remarks.required=select.value==='NG'});resultCell.appendChild(select);remarksCell.appendChild(remarks);
  tr.append(no,item,resultCell,remarksCell);body.appendChild(tr);
 });
 document.getElementById('callbackModal').classList.add('show');
}
function workWeekFromDate(value){if(!value)return 1;const date=new Date(value+'T12:00:00');date.setDate(date.getDate()+4-(date.getDay()||7));const yearStart=new Date(date.getFullYear(),0,1);return Math.ceil((((date-yearStart)/86400000)+1)/7)}
function openEquipment(eq=null){document.getElementById('eqTitle').textContent=eq?'Edit Equipment':'Add Equipment';document.getElementById('eqId').value=eq?eq.id:0;document.getElementById('eqName').value=eq?eq.name:'';document.getElementById('eqCode').value=eq?eq.code:'';const selected=eq?(Array.isArray(eq.planned_frequencies)?eq.planned_frequencies:(eq.planned_frequency?[eq.planned_frequency]:[])):[];document.querySelectorAll('.eq-frequency').forEach(box=>box.checked=selected.includes(box.value));const startDate=eq?(eq.enrolled_on||eq.planned_due_date||'<?=date('Y-m-d')?>'):'<?=date('Y-m-d')?>';document.getElementById('eqEnrollmentDate').value=startDate;document.getElementById('eqStartWeek').value=String(eq&&eq.start_week?eq.start_week:workWeekFromDate(startDate));document.getElementById('equipmentModal').classList.add('show')}
document.getElementById('eqEnrollmentDate').addEventListener('change',event=>{document.getElementById('eqStartWeek').value=String(workWeekFromDate(event.target.value))});
function openAuxInformation(record=null){
 const modal=document.getElementById('auxModal');if(!modal)return;
 document.getElementById('auxTitle').textContent=record?'Edit Equipment / Aux Information':'Add Equipment / Aux Information';
 document.getElementById('auxId').value=record?record.id:0;
 for(let i=0;i<<?=count($data['aux_headers'])?>;i++)document.getElementById('auxField'+i).value=record&&record.values?record.values[i]||'':'';
 modal.classList.add('show');
}
function openChecklistItem(templateId,modelNumber,item=null){const modal=document.getElementById('checklistItemModal');if(!modal)return;document.getElementById('checklistItemTitle').textContent=item?'Edit Checklist Item':'Add Checklist Item';document.getElementById('checklistModelLabel').textContent='Model Number: '+modelNumber;document.getElementById('checklistTemplateId').value=templateId;document.getElementById('checklistItemId').value=item?item.id:0;document.getElementById('checklistItemName').value=item?item.check_item:'';document.querySelectorAll('.checklist-frequency').forEach(box=>box.checked=item&&(item.frequencies||[]).includes(box.value));modal.classList.add('show')}
function printBlankChecklist(id){const source=document.getElementById(id);if(!source)return;const table=source.querySelector('table').cloneNode(true);table.querySelector('colgroup')?.remove();table.tHead.rows[0].deleteCell(-1);Array.from(table.tBodies[0].rows).forEach(row=>{if(row.cells.length>1)row.deleteCell(-1)});['Result','Remarks'].forEach(label=>{const th=document.createElement('th');th.textContent=label;table.tHead.rows[0].appendChild(th)});Array.from(table.tBodies[0].rows).forEach(row=>{if(row.cells.length>1){row.insertCell().innerHTML='&nbsp;';row.insertCell().innerHTML='&nbsp;'}});const title=source.querySelector('h3').textContent;const popup=window.open('','_blank','width=1200,height=850');if(!popup){alert('Please allow pop-ups to export the blank checklist.');return}popup.document.write('<!doctype html><html><head><title>'+title+'</title><style>body{font:11px Arial}h2{color:#5f171c}table{border-collapse:collapse;width:100%;table-layout:fixed}th{background:#5f171c;color:#fff}th,td{border:1px solid #999;padding:7px;text-align:left;vertical-align:top;white-space:normal;overflow-wrap:anywhere}th:nth-child(1),td:nth-child(1){width:5%;text-align:center}th:nth-child(2),td:nth-child(2){width:65%;text-align:justify;line-height:1.35}th:nth-child(3),td:nth-child(3){width:10%;text-align:center}th:nth-child(4),td:nth-child(4){width:20%}@page{size:A4 landscape;margin:10mm}</style></head><body><h2>'+title+' — Blank PM Checklist</h2>'+table.outerHTML+'</body></html>');popup.document.close();popup.focus();setTimeout(()=>popup.print(),250)}
function closeModal(id){document.getElementById(id).classList.remove('show')}
function printRecord(id){printRecordContent(document.getElementById(id),'PM Record')}
function printAllRecords(){printRecordContent(document.getElementById('recordResults'),'PM Records')}
function printRecordContent(source,title){
 if(!source)return;
 const popup=window.open('','_blank','width=1200,height=850');
 if(!popup){alert('Please allow pop-ups to export the PM record to PDF.');return}
 popup.document.write('<!doctype html><html><head><title>'+title+'</title><style>body{font:12px Arial;color:#352a2b;padding:18px}h3{color:#5f171c}.record-actions button{display:none}.record-sheet{border:0!important;break-after:page}.record-meta{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:12px 0}.record-meta>div{padding:8px;background:#faf5f5;border-left:3px solid #a5262e}table{border-collapse:collapse;width:100%;font-size:10px}th{background:#5f171c;color:#fff}th,td{border:1px solid #cfbfc0;padding:6px;text-align:left;vertical-align:top}.badge{font-weight:bold}@page{size:A4 landscape;margin:10mm}</style></head><body>'+source.innerHTML+'</body></html>');
 popup.document.close();popup.focus();setTimeout(()=>popup.print(),250);
}
document.querySelectorAll('.modal').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('show')}));
const scheduleSearch=document.getElementById('search'),dueSearch=document.getElementById('dueSearch'),equipmentFilter=document.getElementById('equipmentFilter');
function applyFilters(){const q=(scheduleSearch||dueSearch)?.value.toLowerCase()||'',code=equipmentFilter?.value||'',table=scheduleSearch?'#scheduleTable':'#dueTable';document.querySelectorAll(table+' tbody tr[data-search]').forEach(r=>r.style.display=(r.dataset.search.includes(q)&&(!code||r.dataset.code===code))?'':'none')}
if(scheduleSearch)scheduleSearch.addEventListener('input',applyFilters);if(dueSearch)dueSearch.addEventListener('input',applyFilters);if(equipmentFilter)equipmentFilter.addEventListener('change',applyFilters);
const auxMachineIdFilter=document.getElementById('auxMachineIdFilter'),auxCategoryFilter=document.getElementById('auxCategoryFilter'),auxMachineNameFilter=document.getElementById('auxMachineNameFilter');
function applyAuxFilters(){
 const machineId=auxMachineIdFilter?.value||'',category=auxCategoryFilter?.value||'',machineName=auxMachineNameFilter?.value||'';
 document.querySelectorAll('#auxTable tbody tr').forEach(row=>row.style.display=(!machineId||row.dataset.machineId===machineId)&&(!category||row.dataset.category===category)&&(!machineName||row.dataset.machineName===machineName)?'':'none');
}
function resetAuxFilters(){if(auxMachineIdFilter)auxMachineIdFilter.value='';if(auxCategoryFilter)auxCategoryFilter.value='';if(auxMachineNameFilter)auxMachineNameFilter.value='';applyAuxFilters()}
[auxMachineIdFilter,auxCategoryFilter,auxMachineNameFilter].forEach(filter=>filter?.addEventListener('change',applyAuxFilters));
function filteredAuxTable(){
 const source=document.getElementById('auxTable');if(!source)return null;
 const clone=source.cloneNode(true);let visibleNumber=1;
 Array.from(clone.tBodies[0].rows).forEach(row=>{if(row.style.display==='none')row.remove();else{row.cells[0].textContent=visibleNumber++;row.deleteCell(-1)}});
 clone.tHead.rows[0].deleteCell(-1);clone.removeAttribute('style');clone.removeAttribute('id');return clone;
}
function printAuxInformation(){
 const table=filteredAuxTable();if(!table)return;const popup=window.open('','_blank','width=1200,height=850');
 if(!popup){alert('Please allow pop-ups to export the information to PDF.');return}
 popup.document.write('<!doctype html><html><head><title>Equipment - Aux Information</title><style>body{font:10px Arial;color:#352a2b}h2{color:#5f171c}table{border-collapse:collapse;width:100%}th{background:#5f171c;color:#fff}th,td{border:1px solid #cfbfc0;padding:5px;text-align:left;vertical-align:top}@page{size:A4 landscape;margin:8mm}</style></head><body><h2>Equipment / Aux Information</h2>'+table.outerHTML+'</body></html>');
 popup.document.close();popup.focus();setTimeout(()=>popup.print(),250);
}
function exportAuxExcel(){
 const table=filteredAuxTable();if(!table)return;
 const content='\ufeff<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"></head><body><h2>Equipment / Aux Information</h2>'+table.outerHTML+'</body></html>';
 const link=document.createElement('a');link.href=URL.createObjectURL(new Blob([content],{type:'application/vnd.ms-excel'}));link.download='Equipment_Aux_Information_<?=date('Y-m-d')?>.xls';link.click();URL.revokeObjectURL(link.href);
}
function exportCsv(){const rows=[['No.','Equipment Name','Equipment ID',...Array.from({length:52},(_,i)=>'Week '+(i+1))]];document.querySelectorAll('#scheduleTable tbody tr').forEach((tr,i)=>{if(tr.style.display==='none')return;const cells=tr.querySelectorAll('td');rows.push([i+1,cells[1].textContent.trim(),cells[2].textContent.trim(),...Array.from(tr.querySelectorAll('.freq')).map(b=>displayFrequencyCode(b.dataset.freq))])});const csv=rows.map(r=>r.map(v=>'"'+String(v).replaceAll('"','""')+'"').join(',')).join('\r\n');const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download='PM_Schedule_<?=$data['year']?>.csv';a.click();URL.revokeObjectURL(a.href)}
document.querySelectorAll('#signupModal input[name="email"],#signupModal input[name="photo"],form input[name="email"]').forEach(input=>input.required=false);
</script></body></html>
