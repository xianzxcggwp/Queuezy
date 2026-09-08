<?php 
require_once '../database/db.php';

$msg = '';
$msgType = 'success';

// Handle Server-Side Actions (Create, Update & Delete Services in MySQL)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create' && $conn) {
        $service_name = trim($_POST['service_name'] ?? '');
        $dept_id = (int)($_POST['dept_id'] ?? 0);
        $est_time = (int)($_POST['estimated_time_mins'] ?? 10);

        if ($service_name !== '' && $dept_id > 0) {
            $stmt = $conn->prepare("INSERT INTO services (dept_id, service_name, estimated_time_mins) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("isi", $dept_id, $service_name, $est_time);
                if ($stmt->execute()) {
                    header("Location: template02.php?created=1");
                    exit;
                } else {
                    $msg = "Error creating service in database.";
                    $msgType = "error";
                }
                $stmt->close();
            }
        } else {
            $msg = "Please select a department and enter a service name.";
            $msgType = "error";
        }
    }

    if ($_POST['action'] === 'update_time' && $conn) {
      $serviceId = (int)($_POST['service_id'] ?? 0);
      $estTime = (int)($_POST['estimated_time_mins'] ?? 0);
      if ($serviceId > 0 && $estTime >= 1 && $estTime <= 120) {
        $stmt = $conn->prepare('UPDATE services SET estimated_time_mins = ? WHERE id = ?');
        if ($stmt) {
          $stmt->bind_param('ii', $estTime, $serviceId);
          if ($stmt->execute()) {
            header('Location: template02.php?updated=1');
            exit;
          }
          $stmt->close();
        }
        $msg = 'We could not update the service time.';
        $msgType = 'error';
      } else {
        $msg = 'Please enter a queue time between 1 and 120 minutes.';
        $msgType = 'error';
      }
    }

    if ($_POST['action'] === 'delete' && $conn) {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        if ($serviceId > 0) {
            $stmt = $conn->prepare("DELETE FROM services WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $serviceId);
                $stmt->execute();
                $stmt->close();
                header("Location: template02.php?deleted=1");
                exit;
            }
        }
    }
}

  if ($conn) {
    $catalog = [
      ['Hair Care & Hair Styling Department', 'HCHS', [
        'Women\'s Precision Cut & Style', 'Men\'s Classic Haircut', 'Children\'s Haircut (12 & under)', 'Bangs / Fringe Trim', 'Split-End Removal Treatment',
        'Signature Blowout', 'Wash, Blow-dry & Curling / Flat Iron', 'Formal Updo / Evening Styling', 'Bridal Hair Styling (Trial & Event Day)', 'Braids, Cornrows & Twists', 'Shampoo & Set',
        'Single-Process All-Over Color', 'Root Touch-Up & Gray Coverage', 'Traditional Highlights (Full Head / Half Head)', 'Balayage / Ombre / Color Melting', 'Baby Lights / Money Piece Highlights', 'Gloss / Toner / Shine Treatment', 'Color Correction', 'Creative / Pastel Fashion Colors',
        'Brazilian Blowout / Keratin Treatment', 'Thermal Rebonding / Hair Straightening', 'Nanoplasty Smoothing', 'Digital Perm / Cold Wave Perm', 'Chemical Hair Relaxer',
        'Olaplex Bond Building Treatment', 'Deep Conditioning Moisture Mask', 'Scalp Detox & Exfoliating Scrub', 'Hair Botox Therapy', 'Anti-Dandruff Intensive Scalp Care', 'Hair Loss & Thinning Therapy',
        'Tape-In Extensions Installation', 'K-Tip / I-Tip Extensions', 'Sew-In / Weave Extensions', 'Extension Removal, Re-fitting & Maintenance',
      ]],
      ["Barbering & Men's Grooming Department", 'BMG', [
        'Precision Fade (Skin Fade, Drop Fade, Taper Fade)', 'Men\'s Classic Scissors Cut', 'Buzz Cut & Line-Up', 'Hair Tattooing & Razor Designs', 'Hot Towel Straight Razor Shave', 'Beard Sculpting & Line-Up', 'Beard Trimming & Conditioning', 'Mustache Grooming & Waxing', 'Beard Color & Gray Blending', 'Scalp & Shoulder Massage', 'Men\'s Deep Cleanse Express Facial', 'Nose & Ear Waxing', 'Eyebrow Cleanup & Shaping',
      ]],
      ['Nail Care & Enhancements Department', 'NCE', [
        'Classic Manicure / Pedicure', 'Gel Manicure / Pedicure', 'Russian Dry Manicure', 'Express Nail Cleanup', 'Sports / Men\'s Nail Care', 'Paraffin Wax Treatment (Hands / Feet)', 'Hydrating Hand & Foot Spa', 'Intensive Callus Removal', 'Full Set Acrylic Extensions', 'Builder Gel / BIAB (Builder in a Bottle)', 'Polygel Extensions', 'Dip Powder (SNS) Nails', 'Hard Gel Overlay', 'Nail Extension Removal & Refill', 'Hand-Painted Nail Art', 'Chrome / Holographic Finish', 'French Tip Design', 'Rhinestone & 3D Embellishments', 'Cat-Eye Gel Polish', 'Single Nail Repair',
      ]],
      ['Skin Care & Esthetics Department', 'SCE', [
        'Deep Cleansing Facial', 'Hydrating / Moisture Surge Facial', 'Gentle Sensitive Skin Facial', 'European Relaxing Facial', 'HydraFacial', 'Microdermabrasion / Diamond Peel', 'Chemical Peels (Glycolic, Salicylic, TCA)', 'LED Light Therapy (Red/Blue Light)', 'Oxygen Infusion Facial', 'Back Facial (Back Acne Treatment)', 'Milia / Extraction Treatment', 'Chest & Neck Decongestant Treatment', 'Hyperpigmentation / Dark Spot Corrector',
      ]],
      ['Medical Aesthetics Department (MedSpa)', 'MED', [
        'Botox / Neurotoxin Injections', 'Dermal Fillers (Lips, Cheeks, Jawline, Under-Eye)', 'Lip Flip', 'Profhilo Skin Remodeling', 'Kybella Fat Dissolving (Double Chin)', 'CO2 Fractional Laser Resurfacing', 'Carbon Laser Peel (Hollywood Peel)', 'Laser Tattoo Removal', 'Spider Vein & Pigmentation Removal', 'Microneedling with Hyaluronic Acid', 'PRP Vampire Facial (Platelet-Rich Plasma)', 'Exosome Therapy', 'PDO Thread Lift', 'CoolSculpting (Cryolipolysis)', 'Emsculpt Muscle Toning', 'Ultrasonic Cavitation & RF Slimming', 'Endermologie Cellulite Reduction',
      ]],
      ['Lash, Brow & Semi-Permanent Makeup (PMU) Department', 'PMU', [
        'Classic Lash Extensions', 'Hybrid Lash Extensions', 'Volume & Mega Volume Lash Extensions', 'Lash Lift & Tint', 'Lash Extension Removal', 'Lash Nourishing Botox Treatment', 'Eyebrow Threading', 'Eyebrow Waxing & Tweezing', 'Brow Lamination', 'Brow Tinting', 'Henna Brows', 'Microblading / Hair-Stroke Brows', 'Powder / Ombre Brows', 'Combination Brows', 'Lip Blush Tattoo', 'Tightline Eyeliner Tattoo', 'PMU Color Correction or Removal',
      ]],
      ['Body & Massage Therapy Department', 'BMT', [
        'Swedish Massage', 'Deep Tissue Massage', 'Hot Stone Massage', 'Aromatherapy Massage', 'Prenatal / Pregnancy Massage', 'Foot Reflexology', 'Shiatsu Massage', 'Exfoliating Body Scrub / Polish', 'Detoxifying Body Wrap', 'Lymphatic Drainage Massage', 'Back Scrub & Massage Combo', 'Custom Airbrush Spray Tan', 'Express Rapid Spray Tan', 'Sunbed UV Tanning',
      ]],
      ['Hair Removal Department', 'HR', [
        'Full Body Waxing', 'Brazilian & Bikini Waxing', 'Underarm Waxing', 'Leg & Arm Waxing', 'Facial Waxing (Lip, Chin, Cheeks)', 'Full Face Threading', 'Organic Body Sugaring', 'Diode Laser Hair Removal', 'IPL (Intense Pulsed Light) Hair Removal',
      ]],
      ['Wellness & Holistic Health Department', 'WHH', [
        'Infrared Sauna Session', 'Cold Plunge Therapy', 'Salt Room / Halotherapy', 'Acupuncture', 'Cupping Therapy (Venting)', 'Reiki Energy Healing', 'Hydration IV Drip', 'Immunity / Vitamin C Drip', 'Glutathione Glow Drip', 'Vitamin B12 Shot', 'NAD+ Anti-Aging Injections',
      ]],
      ['Piercing & Body Modification Department', 'PBM', [
        'Ear Lobe Piercing', 'Cartilage / Helix / Tragus Piercing', 'Nose (Nostril / Septum) Piercing', 'Lip & Tongue Piercing', 'Navel Piercing', 'Nipple & Surface Piercings', 'Implant-Grade Titanium/Gold Jewelry Fitting', 'Post-Piercing Downsizing & Check-Up', 'Piercing Hole Tapering & Re-Opening',
      ]],
      ['Makeup & Glamour Department', 'MGP', [
        'Day / Natural Glam Makeup', 'Full Evening Glam Makeup', 'Airbrush Makeup', 'Special Effects (SFX) / Creative Makeup', 'Bridal Makeup (Trial & Wedding Day)', 'Bridesmaids & Entourage Makeup', 'On-Site Group Event Glam',
      ]],
      ['Retail & Boutique Department', 'RBT', [
        'Professional Shampoo & Conditioner Sets', 'Hair Masks, Oils & Leave-in Conditioners', 'Serums, Sunscreen & Skincare Products', 'Cuticle Oils & Hand Creams', 'Hot Hair Tools (Straighteners, Curling Wands)', 'Silk Pillowcases & Scrunchies', 'Scalp Massagers & Hair Brushes', 'Eyelash & Eyebrow Aftercare Kits',
      ]],
    ];

    $departmentStmt = $conn->prepare('INSERT INTO departments (dept_name, dept_code, status) SELECT ?, ?, \'active\' WHERE NOT EXISTS (SELECT 1 FROM departments WHERE dept_name = ?)');
    $serviceStmt = $conn->prepare('INSERT INTO services (dept_id, service_name, estimated_time_mins) SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM services WHERE service_name = ?)');
    if ($departmentStmt && $serviceStmt) {
      foreach ($catalog as [$departmentName, $departmentCode, $serviceNames]) {
        $departmentStmt->bind_param('sss', $departmentName, $departmentCode, $departmentName);
        $departmentStmt->execute();
        $departmentResult = $conn->query("SELECT id FROM departments WHERE dept_name = '" . $conn->real_escape_string($departmentName) . "' LIMIT 1");
        $department = $departmentResult ? $departmentResult->fetch_assoc() : null;
        $departmentId = (int)($department['id'] ?? 0);
        foreach ($serviceNames as $serviceName) {
          $estimatedTime = 30;
          $serviceStmt->bind_param('isis', $departmentId, $serviceName, $estimatedTime, $serviceName);
          $serviceStmt->execute();
        }
      }
    }
    $departmentStmt?->close();
    $serviceStmt?->close();
  }

// Fetch ONLY Services that exist in MySQL
$serviceList = [];
$deptList = [];

if ($conn) {
    // Fetch departments for dropdown picker & statistics
    $dRes = $conn->query("SELECT id, dept_name, dept_code FROM departments ORDER BY id ASC");
    if ($dRes) {
        while ($dRow = $dRes->fetch_assoc()) {
            $deptList[] = $dRow;
        }
    }

    // Fetch services joined with department details
    $sRes = $conn->query("SELECT s.*, d.dept_name, d.dept_code FROM services s LEFT JOIN departments d ON s.dept_id = d.id ORDER BY s.id ASC");
    if ($sRes) {
        while ($sRow = $sRes->fetch_assoc()) {
            $serviceList[] = $sRow;
        }
    }
}

$pageTitle = 'Services | Queuezy Admin'; 
$pageHeading = 'Services Catalog'; 
require_once '../includes/header.php'; 
?>

<!-- Embedded Custom CSS for Senior-Grade UI/UX & Micro-interactions -->
<style>
  .stat-card-luxury {
    position: relative;
    overflow: hidden;
    background: #ffffff;
    border: 1px solid #ece9f4;
    border-radius: 20px;
    padding: 24px;
    transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 20px -2px rgba(57, 35, 97, 0.04);
  }
  .stat-card-luxury:hover {
    transform: translateY(-4px);
    box-shadow: 0 16px 36px -4px rgba(124, 58, 237, 0.12);
    border-color: #d8b4fe;
  }
  .stat-card-luxury::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, #7c3aed, #a855f7);
    opacity: 0;
    transition: opacity 0.25s ease;
  }
  .stat-card-luxury:hover::after { opacity: 1; }

  .service-card-lux {
    background: #ffffff;
    border: 1px solid #ece9f4;
    border-radius: 20px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 20px -2px rgba(57, 35, 97, 0.03);
    position: relative;
  }
  .service-card-lux:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 40px -8px rgba(124, 58, 237, 0.12);
    border-color: #c084fc;
  }
  body.dark-mode .service-card-lux {
    background: #1e293b;
    border-color: #334155;
    color: #f8fafc;
  }
  body.dark-mode .service-card-lux h3,
  body.dark-mode .service-card-lux .font-black.text-slate-900 {
    color: #f8fafc !important;
  }
  body.dark-mode .service-card-lux .text-slate-500 {
    color: #cbd5e1 !important;
  }
  body.dark-mode .service-card-lux .bg-slate-50 {
    background: #273449 !important;
    border-color: #475569 !important;
  }
  body.dark-mode .service-card-lux .border-slate-100 {
    border-color: #475569 !important;
  }
  body.dark-mode .service-card-lux .bg-purple-50 {
    background: #2e1b52 !important;
    color: #d8b4fe !important;
    border-color: #6d28d9 !important;
  }
  body.dark-mode .service-card-lux .bg-purple-50:hover {
    background: #3b2466 !important;
  }
  body.dark-mode .service-card-lux .bg-red-50 {
    background: #451a1a !important;
    color: #fca5a5 !important;
    border-color: #991b1b !important;
  }
  body.dark-mode .service-card-lux .bg-red-50:hover {
    background: #5b2020 !important;
  }
  body.dark-mode .service-metrics .bg-purple-50 {
    background: #2e1b52 !important;
  }
  body.dark-mode .service-metrics .bg-indigo-50 {
    background: #1e1b4b !important;
  }
  body.dark-mode .service-metrics .bg-emerald-50 {
    background: #052e2b !important;
  }
  body.dark-mode .service-metrics .bg-amber-50 {
    background: #422006 !important;
  }

  .filter-pill {
    padding: 8px 18px;
    border-radius: 14px;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid #ece9f4;
    background: #ffffff;
    color: #64748b;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }
  .filter-pill:hover {
    border-color: #c084fc;
    color: #7c3aed;
    background: #faf5ff;
  }
  .filter-pill.active {
    background: #7c3aed;
    color: #ffffff;
    border-color: #7c3aed;
    box-shadow: 0 4px 16px rgba(124, 58, 237, 0.3);
  }

  .hidden { display: none !important; }
  #confirmTimeModal.hidden { display: none !important; }

  /* Toast Notification */
  .toast-container {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
  }
  .toast-item {
    background: #1e1b4b;
    color: #ffffff;
    padding: 14px 20px;
    border-radius: 16px;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
    pointer-events: auto;
    animation: toastSlideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards;
  }
  @keyframes toastSlideIn {
    from { opacity: 0; transform: translateY(16px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }
</style>

<!-- Top Breadcrumbs & Action Bar -->
<div class="mb-8">
  <div class="flex flex-wrap items-center justify-between gap-4">
   
      <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Services Directory</h1>
    

  </div>
</div>

<?php if ($msg): ?>
<div class="mb-6 p-4 rounded-2xl border <?= $msgType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?> font-semibold text-xs flex items-center gap-2">
  <i data-feather="<?= $msgType === 'error' ? 'alert-circle' : 'check-circle' ?>" class="w-4 h-4"></i>
  <span><?= htmlspecialchars($msg) ?></span>
</div>
<?php endif; ?>

<!-- Executive Metric Cards -->
<div class="service-metrics grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-8">
  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Services</span>
      <div class="w-10 h-10 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center">
        <i class="fa-solid fa-briefcase" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-slate-900"><?= count($serviceList) ?></h3>
      <p class="text-xs text-slate-500 mt-2 flex items-center gap-1">
        <i data-feather="check-circle" class="w-3.5 h-3.5 text-emerald-500"></i>
        <span>Active services</span>
      </p>
    </div>
  </div>

  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Departments Listed</span>
      <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
        <i class="fa-solid fa-layer-group" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-indigo-600"><?= count($deptList) ?></h3>
      <p class="text-xs text-slate-500 mt-2">Active queue divisions</p>
    </div>
  </div>

  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Avg Processing Time</span>
      <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
        <i class="fa-solid fa-clock" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <?php 
        $avgTime = count($serviceList) > 0 ? round(array_sum(array_column($serviceList, 'estimated_time_mins')) / count($serviceList), 1) : 0;
      ?>
      <h3 class="text-3xl font-black text-slate-900"><?= $avgTime ?> <span class="text-sm font-semibold text-slate-400">mins</span></h3>
      <p class="text-xs text-emerald-600 font-semibold mt-2">Estimated queue SLA</p>
    </div>
  </div>

  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">System Status</span>
      <div class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center">
        <i class="fa-solid fa-circle-check" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-emerald-600">Active</h3>
      <p class="text-xs text-slate-500 mt-2">Service catalog online</p>
    </div>
  </div>
</div>

<!-- Toolbar & Search -->
<div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm mb-8 flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4">
  <div class="relative flex-1 max-w-md md:ml-auto">
    <i data-feather="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
    <input type="text" id="serviceSearchInput" onkeyup="filterServices()" placeholder="Search service name or department..." class="w-full pl-10 pr-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-purple-500 focus:bg-white transition">
  </div>
</div>

<!-- Services Cards Grid -->
<?php if (empty($serviceList)): ?>
<div class="py-16 text-center bg-white border border-[#ece9f4] rounded-2xl p-8">
  <div class="w-16 h-16 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center mx-auto mb-4">
    <i data-feather="briefcase" class="w-7 h-7"></i>
  </div>
  <h3 class="font-extrabold text-lg text-slate-800">No services found</h3>
  <p class="text-xs text-slate-400 max-w-sm mx-auto mt-1">Services configured by administrators will appear here.</p>
</div>
<?php else: ?>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="servicesGrid">
  <?php foreach ($serviceList as $s): ?>
  <div class="service-card-lux service-item" data-name="<?= htmlspecialchars(strtolower($s['service_name'])) ?>" data-dept="<?= htmlspecialchars(strtolower($s['dept_name'] ?? '')) ?>">
    <div>
      <div class="flex items-center justify-between mb-4">
        <span class="px-3 py-1 rounded-full text-[11px] font-extrabold uppercase bg-purple-50 text-purple-700 border border-purple-200">
          <?= htmlspecialchars($s['dept_name'] ?? 'General') ?> (<?= htmlspecialchars($s['dept_code'] ?? 'ID ' . $s['dept_id']) ?>)
        </span>
      </div>

      <h3 class="text-lg font-black text-slate-900 mb-2"><?= htmlspecialchars($s['service_name']) ?></h3>
      <p class="text-xs text-slate-500 mb-4">Official service processing line.</p>

      <div class="flex items-center justify-between text-xs py-2 px-3 rounded-xl bg-slate-50 mb-4 border border-slate-100">
        <span class="text-slate-500 font-semibold flex items-center gap-1.5">
          <i data-feather="clock" class="w-3.5 h-3.5 text-purple-600"></i> Est. Processing Time:
        </span>
        <span class="font-black text-slate-900"><?= (int)$s['estimated_time_mins'] ?> mins</span>
      </div>
    </div>

    <div class="pt-4 border-t border-slate-100 flex items-center justify-between mt-2">
      <span class="text-xs font-bold text-emerald-600 flex items-center gap-1.5">
        <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Active
      </span>

      <div class="flex items-center gap-2">
        <button type="button" onclick="openEditTimeModal(<?= (int)$s['id'] ?>, <?= (int)$s['estimated_time_mins'] ?>, '<?= htmlspecialchars($s['service_name'], ENT_QUOTES) ?>')" class="px-3 py-1.5 rounded-xl border border-purple-200 bg-purple-50 hover:bg-purple-100 text-purple-700 font-bold text-xs transition inline-flex items-center gap-1.5" title="Edit queue time">
          <i data-feather="edit-3" class="w-3.5 h-3.5"></i>
          <span>EDIT</span>
        </button>
        <form method="post" class="inline" onsubmit="return confirm('Permanently delete service <?= htmlspecialchars($s['service_name'], ENT_QUOTES) ?>?');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
        <button type="submit" class="px-3 py-1.5 rounded-xl border border-red-200 bg-red-50 hover:bg-red-100 text-red-600 font-bold text-xs transition inline-flex items-center gap-1.5 shadow-sm" title="Delete Service">
          <i data-feather="trash-2" class="w-3.5 h-3.5"></i>
          <span>DELETE</span>
        </button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Edit Service Time Modal -->
<div id="editTimeModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
  <div class="bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl p-6">
    <div class="flex items-center justify-between pb-4 border-b border-slate-100">
      <div>
        <h3 class="font-black text-lg text-slate-900">Edit Queue Time</h3>
        <p id="editTimeServiceName" class="text-xs text-slate-500">Update service processing time.</p>
      </div>
    </div>
    <form method="post" class="space-y-4 mt-4" id="editTimeForm">
      <input type="hidden" name="action" value="update_time">
      <input type="hidden" name="service_id" id="editTimeServiceId">
      <div>
        <label class="block text-xs font-bold text-slate-700 uppercase mb-1" for="editTimeInput">Estimated Time (Minutes)</label>
        <input type="number" name="estimated_time_mins" id="editTimeInput" required min="1" max="120" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs outline-none focus:border-purple-600 focus:ring-2 focus:ring-purple-100">
      </div>
      <div class="pt-3 flex justify-end gap-2 border-t border-slate-100">
        <button type="button" onclick="closeEditTimeModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 text-xs font-semibold hover:bg-slate-50">Cancel</button>
        <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white text-xs font-bold hover:bg-purple-700 shadow-md">Save Time</button>
      </div>
    </form>
  </div>
</div>

<!-- Save Time Confirmation -->
<div id="confirmTimeModal" class="fixed inset-0 z-[60] bg-slate-950/70 backdrop-blur-sm hidden flex items-center justify-center p-4">
  <div class="bg-slate-800 border border-slate-600 rounded-2xl w-full max-w-sm p-6 shadow-2xl">
    <div class="w-11 h-11 rounded-full bg-purple-500/20 text-purple-300 flex items-center justify-center mb-4">
      <i class="fa-solid fa-clock" aria-hidden="true"></i>
    </div>
    <h3 class="text-lg font-black text-white">Save queue time?</h3>
    <p class="text-sm text-slate-300 mt-2">Update <strong id="confirmTimeServiceName" class="text-white"></strong> with the new processing time?</p>
    <div class="flex justify-end gap-2 mt-6">
      <button type="button" onclick="closeConfirmTimeModal()" class="px-4 py-2.5 rounded-xl border border-slate-500 text-slate-200 text-xs font-semibold hover:bg-slate-700">Cancel</button>
      <button type="button" onclick="submitEditTimeForm()" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white text-xs font-bold hover:bg-purple-700">Save Time</button>
    </div>
  </div>
</div>

<div class="toast-container" id="toastBox"></div>

<script>
  function filterServices() {
    const q = document.getElementById('serviceSearchInput').value.toLowerCase().trim();
    const items = document.querySelectorAll('.service-item');
    items.forEach(item => {
      const name = item.getAttribute('data-name') || '';
      const dept = item.getAttribute('data-dept') || '';
      if (!q || name.includes(q) || dept.includes(q)) {
        item.style.display = '';
      } else {
        item.style.display = 'none';
      }
    });
  }

  function openEditTimeModal(serviceId, estimatedTime, serviceName) {
    document.getElementById('editTimeServiceId').value = serviceId;
    document.getElementById('editTimeInput').value = estimatedTime;
    document.getElementById('editTimeServiceName').textContent = serviceName;
    document.getElementById('editTimeModal').classList.remove('hidden');
    if (typeof feather !== 'undefined') feather.replace();
    document.getElementById('editTimeInput').focus();
  }

  function closeEditTimeModal() {
    document.getElementById('editTimeModal').classList.add('hidden');
  }

  function openConfirmTimeModal() {
    document.getElementById('confirmTimeServiceName').textContent = document.getElementById('editTimeServiceName').textContent;
    document.getElementById('confirmTimeModal').classList.remove('hidden');
  }

  function closeConfirmTimeModal() {
    document.getElementById('confirmTimeModal').classList.add('hidden');
  }

  function submitEditTimeForm() {
    closeConfirmTimeModal();
    document.getElementById('editTimeForm').submit();
  }

  function showToast(msg) {
    const toastBox = document.getElementById('toastBox');
    const toast = document.createElement('div');
    toast.className = 'toast-item';
    toast.innerHTML = `<i data-feather="check" class="w-4 h-4 text-emerald-300"></i><span>${msg}</span>`;
    toastBox.appendChild(toast);
    if (typeof feather !== 'undefined') feather.replace();
    setTimeout(() => { toast.remove(); }, 3200);
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (typeof feather !== 'undefined') {
      feather.replace();
    }
    const queueMenu = document.getElementById('queueMenu');
    const queueSidebar = document.getElementById('queueSidebar');
    if (queueMenu && queueSidebar) {
      queueMenu.addEventListener('click', () => queueSidebar.classList.toggle('is-open'));
    }
    document.getElementById('editTimeForm')?.addEventListener('submit', (event) => {
      event.preventDefault();
      openConfirmTimeModal();
    });

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('created')) {
      showToast('Service created and saved in MySQL!');
    }
    if (urlParams.has('deleted')) {
      showToast('Service deleted from MySQL database.');
    }
  });
</script>

<?php require_once '../includes/footer.php'; ?>
</div></main></div></body></html>
