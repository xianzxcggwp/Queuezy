<?php 
require_once '../database/db.php';
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';

// Handle Server-Side Actions (Create, Delete)
$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create' && $conn && $isAdmin) {
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = strtolower(trim($_POST['role'] ?? 'customer'));

        if ($fullname !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($password) >= 8 && strlen($password) <= 15) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (fullname, email, password, role) VALUES (?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("ssss", $fullname, $email, $hashed, $role);
                if ($stmt->execute()) {
                    header("Location: template04.php?created=1");
                    exit;
                } else {
                    $msg = "Error: Email might already be registered.";
                    $msgType = "error";
                }
                $stmt->close();
            }
        } else {
            $msg = "Password must be between 8 and 15 characters, and all fields must be valid.";
            $msgType = "error";
        }
    }

    if ($_POST['action'] === 'update_role' && $conn && $isAdmin) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newRole = strtolower(trim($_POST['role'] ?? 'customer'));
        if ($userId > 0 && in_array($newRole, ['customer', 'staff', 'admin'], true)) {
            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $newRole, $userId);
                $stmt->execute();
                $stmt->close();
                header("Location: template04.php?role_updated=1");
                exit;
            }
        }
    }

    if ($_POST['action'] === 'delete' && $conn && $isAdmin) {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $stmt->close();
                header("Location: template04.php?deleted=1");
                exit;
            }
        }
    }
}

// Fetch users from database
$userList = [];
if ($conn) {
    $result = $conn->query("SELECT id, fullname, email, role, created_at FROM users ORDER BY id ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $userList[] = [
                'id' => (int)$row['id'],
                'name' => $row['fullname'] ?? 'User',
                'email' => $row['email'] ?? '',
                'role' => strtolower($row['role'] ?? 'customer'),
                'created_at' => $row['created_at'] ?? 'Just now'
            ];
        }
    }
}

$adminCount = count(array_filter($userList, fn($u) => in_array($u['role'], ['admin', 'superadmin'], true)));
$staffCount = count(array_filter($userList, fn($u) => $u['role'] === 'staff'));
$customerCount = count(array_filter($userList, fn($u) => in_array($u['role'], ['customer', 'user', 'student'], true)));
$totalCount = count($userList);

$pageTitle = 'Users Management | Queuezy Admin'; 
$pageHeading = 'Users Directory'; 
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
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #7c3aed, #a855f7);
    opacity: 0;
    transition: opacity 0.25s ease;
  }
  .stat-card-luxury:hover::after {
    opacity: 1;
  }

  .role-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .role-badge.admin { background: #fdf2f8; color: #db2777; border: 1px solid #fbcfe8; }
  .role-badge.staff { background: #f3e8ff; color: #7c3aed; border: 1px solid #e9d5ff; }
  .role-badge.customer { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
  body.dark-mode .users-metrics .bg-purple-50 { background: #2e1b52 !important; }
  body.dark-mode .users-metrics .bg-indigo-50 { background: #1e1b4b !important; }
  body.dark-mode .users-metrics .bg-emerald-50 { background: #052e2b !important; }
  body.dark-mode .users-metrics .bg-pink-50 { background: #4a1735 !important; }
  body.dark-mode .role-badge.admin,
  body.dark-mode .user-avatar.admin { background: #4a1735; color: #f9a8d4; border-color: #9d174d; }
  body.dark-mode .role-badge.staff,
  body.dark-mode .user-avatar.staff { background: #2e1b52; color: #d8b4fe; border-color: #6d28d9; }
  body.dark-mode .role-badge.customer,
  body.dark-mode .user-avatar.customer { background: #052e2b; color: #6ee7b7; border-color: #047857; }
  body.dark-mode .users-controls,
  body.dark-mode .users-table-card { background: #1e293b !important; border-color: #334155 !important; }
  body.dark-mode .users-controls input,
  body.dark-mode .users-table-card select { background: #0f172a !important; border-color: #475569 !important; color: #f8fafc !important; }
  body.dark-mode .users-table-card .bg-emerald-50 { background: #052e2b !important; color: #6ee7b7 !important; border-color: #047857 !important; }
  body.dark-mode .users-table-card .bg-red-50 { background: #451a1a !important; color: #fca5a5 !important; border-color: #991b1b !important; }

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
  .filter-pill .count-tag {
    font-size: 11px;
    padding: 1px 7px;
    border-radius: 9999px;
    background: rgba(0,0,0,0.06);
  }
  .filter-pill.active .count-tag {
    background: rgba(255,255,255,0.25);
    color: #ffffff;
  }

  .user-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 16px;
    flex-shrink: 0;
  }
  .user-avatar.admin { background: #fdf2f8; color: #db2777; }
  .user-avatar.staff { background: #f3e8ff; color: #7c3aed; }
  .user-avatar.customer { background: #ecfdf5; color: #059669; }

  #usersTable thead th {
    white-space: nowrap;
    font-size: 11px;
    letter-spacing: 0.08em;
  }
  html.dark-mode #usersTable thead,
  body.dark-mode #usersTable thead { background: #172033; }
  html.dark-mode #usersTable thead tr,
  body.dark-mode #usersTable thead tr { background: #172033 !important; border-color: #475569 !important; }
  html.dark-mode #usersTable thead th,
  body.dark-mode #usersTable thead th { color: #f8fafc !important; background: #172033 !important; }
  html.dark-mode #usersTable tbody td,
  body.dark-mode #usersTable tbody td { color: #f8fafc !important; }
  html.dark-mode #usersTable tbody .text-slate-400,
  html.dark-mode #usersTable tbody .text-slate-500,
  html.dark-mode #usersTable tbody .text-slate-600,
  body.dark-mode #usersTable tbody .text-slate-400,
  body.dark-mode #usersTable tbody .text-slate-500,
  body.dark-mode #usersTable tbody .text-slate-600 { color: #cbd5e1 !important; }

  .hidden { display: none !important; }
  #usersTableBody { transition: opacity 180ms ease, transform 180ms ease; }
  #usersTableBody.is-filtering { opacity: 0; transform: translateY(4px); }

  /* Fallback table & modal rules */
  table { width: 100%; border-collapse: collapse; }
  #addUserModal.hidden, #emptyState.hidden { display: none !important; }

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
    <div>
     
      <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Users Directory</h1>
      
    </div>

    <!-- Top Action Buttons -->
    <div class="flex items-center gap-3">
      <button type="button" onclick="window.print()" class="px-4 py-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-sm shadow-sm transition inline-flex items-center gap-2">
        <i class="fa-solid fa-print w-4 h-4 text-slate-500" aria-hidden="true"></i>
        <span class="hidden sm:inline">Export Roster</span>
      </button>

      <?php if ($isAdmin): ?>
      <button type="button" onclick="openAddUserModal()" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 text-white font-bold text-sm shadow-md hover:from-purple-700 hover:to-indigo-700 transition inline-flex items-center gap-2">
        <i data-feather="user-plus" class="w-4 h-4"></i>
        <span>Add New User</span>
      </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($msg): ?>
<div class="mb-6 p-4 rounded-2xl border <?= $msgType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?> font-semibold text-xs flex items-center gap-2">
  <i data-feather="<?= $msgType === 'error' ? 'alert-circle' : 'check-circle' ?>" class="w-4 h-4"></i>
  <span><?= htmlspecialchars($msg) ?></span>
</div>
<?php endif; ?>

<!-- Executive Metric Cards -->
<div class="users-metrics grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-8">
  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Users</span>
      <div class="w-10 h-10 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center">
        <i class="fa-solid fa-users" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-slate-900"><?= $totalCount ?></h3>
      <p class="text-xs text-slate-500 mt-2 flex items-center gap-1">
        <i data-feather="check-circle" class="w-3.5 h-3.5 text-emerald-500"></i>
        <span>Active accounts</span>
      </p>
    </div>
  </div>

  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Staff Officers</span>
      <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
        <i class="fa-solid fa-award" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-indigo-600"><?= $staffCount ?></h3>
      <p class="text-xs text-slate-500 mt-2 flex items-center gap-1">
        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
        <span>Desk call privileges</span>
      </p>
    </div>
  </div>

  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Customers / Students</span>
      <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
        <i class="fa-solid fa-mobile-screen-button" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-slate-900"><?= $customerCount ?></h3>
      <p class="text-xs text-emerald-600 font-semibold mt-2">Mobile queue users</p>
    </div>
  </div>

  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">System Admins</span>
      <div class="w-10 h-10 rounded-2xl bg-pink-50 text-pink-600 flex items-center justify-center">
        <i class="fa-solid fa-shield-halved" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-pink-600"><?= $adminCount ?></h3>
      <p class="text-xs text-slate-500 mt-2">Full system access</p>
    </div>
  </div>
</div>

<!-- Controls Ribbon: Filter Pills & Search Bar -->
<div class="users-controls bg-white border border-[#ece9f4] rounded-2xl p-4 mb-8 shadow-sm flex flex-col lg:flex-row lg:items-center justify-between gap-4">
  <!-- Role Filter Pills -->
  <div class="flex flex-wrap items-center gap-2" id="filterContainer">
    <button class="filter-pill active" data-role="all">
      <span>All Users</span>
      <span class="count-tag"><?= $totalCount ?></span>
    </button>
    <button class="filter-pill" data-role="admin">
      <span>Admins</span>
      <span class="count-tag"><?= $adminCount ?></span>
    </button>
    <button class="filter-pill" data-role="staff">
      <span>Staff Officers</span>
      <span class="count-tag"><?= $staffCount ?></span>
    </button>
    <button class="filter-pill" data-role="customer">
      <span>Customers</span>
      <span class="count-tag"><?= $customerCount ?></span>
    </button>
  </div>

  <!-- Search Input -->
  <div class="relative w-full sm:w-72">
    <i data-feather="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
    <input type="text" id="userSearch" placeholder="Search by name, email or role..." class="w-full bg-white border border-[#ece9f4] rounded-xl pl-10 pr-4 py-2 text-xs font-medium outline-none focus:border-purple-600 focus:ring-2 focus:ring-purple-100 transition">
  </div>
</div>

<!-- Users Table Card -->
<div class="users-table-card bg-white border border-[#ece9f4] rounded-2xl shadow-sm overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-left border-collapse text-xs" id="usersTable">
      <thead>
        <tr class="bg-slate-50/80 border-b border-slate-100 text-slate-400 font-bold uppercase tracking-wider text-[11px]">
          <th class="py-4 px-6">ID & USER NAME</th>
          <th class="py-4 px-4">ROLE PERMISSION</th>
          <th class="py-4 px-4">EMAIL ADDRESS</th>
          <th class="py-4 px-4">ACCOUNT CREATED</th>
          <th class="py-4 px-4">STATUS</th>
          <th class="py-4 px-6 text-right">ACTIONS</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 font-medium text-slate-700" id="usersTableBody">
        <?php if (empty($userList)): ?>
        <tr>
          <td colspan="6" class="py-12 text-center text-slate-400">
            No users found. Click "Add New User" to create one.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($userList as $u): 
          $roleClass = in_array($u['role'], ['admin', 'superadmin'], true) ? 'admin' : ($u['role'] === 'staff' ? 'staff' : 'customer');
        ?>
        <tr class="user-row hover:bg-purple-50/40 transition" data-name="<?= htmlspecialchars($u['name']) ?>" data-email="<?= htmlspecialchars($u['email']) ?>" data-role="<?= $roleClass ?>">
          <td class="py-4 px-6">
            <div class="flex items-center gap-3">
              <span class="user-avatar <?= $roleClass ?>" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
              <div>
                <div class="flex items-center gap-2">
                  <strong class="text-slate-900 font-bold text-sm"><?= htmlspecialchars($u['name']) ?></strong>
                </div>
                <span class="text-[11px] text-slate-400 block mt-0.5"><?= ucfirst($roleClass) ?> Account</span>
              </div>
            </div>
          </td>
          <td class="py-4 px-4">
            <?php if ($isAdmin): ?>
            <form method="post" class="inline-flex items-center gap-1.5">
              <input type="hidden" name="action" value="update_role">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <select name="role" onchange="this.form.submit()" class="text-[11px] font-bold px-2.5 py-1 rounded-xl border border-slate-200 bg-white text-slate-700 cursor-pointer hover:border-purple-400 focus:outline-none focus:ring-2 focus:ring-purple-100 transition">
                <option value="customer" <?= $u['role'] === 'customer' ? 'selected' : '' ?>>👤 Customer</option>
                <option value="staff" <?= $u['role'] === 'staff' ? 'selected' : '' ?>>🎖️ Staff</option>
                <option value="admin" <?= in_array($u['role'], ['admin', 'superadmin'], true) ? 'selected' : '' ?>>🛡️ Admin</option>
              </select>
            </form>
            <?php else: ?>
            <span class="text-[11px] font-semibold text-slate-400">Admin only</span>
            <?php endif; ?>
          </td>
          <td class="py-4 px-4 text-slate-600 font-medium font-mono text-[11px]">
            <?= htmlspecialchars($u['email']) ?>
          </td>
          <td class="py-4 px-4 text-slate-500">
            <?= htmlspecialchars($u['created_at'] !== 'Just now' ? date('M d, Y h:i A', strtotime($u['created_at'])) : 'Just now') ?>
          </td>
          <td class="py-4 px-4">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
              Active
            </span>
          </td>
          <td class="py-4 px-6 text-right">
            <?php if ($isAdmin): ?>
            <form method="post" class="inline" onsubmit="return confirm('Permanently delete user <?= htmlspecialchars($u['name'], ENT_QUOTES) ?>?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <button type="submit" class="px-3 py-1.5 rounded-xl border border-red-200 bg-red-50 hover:bg-red-100 text-red-600 font-bold text-xs transition inline-flex items-center gap-1.5 shadow-sm" title="Delete User">
                <i data-feather="trash-2" class="w-3.5 h-3.5"></i>
                <span>DELETE</span>
              </button>
            </form>
            <?php else: ?>
            <span class="text-[11px] font-semibold text-slate-400">Admin only</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Empty State Filter -->
<div id="emptyState" class="hidden py-16 text-center bg-white border border-[#ece9f4] rounded-2xl p-8 mt-6">
  <div class="w-16 h-16 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center mx-auto mb-4">
    <i data-feather="users" class="w-7 h-7"></i>
  </div>
  <h3 class="font-extrabold text-lg text-slate-800">No matching users found</h3>
  <p class="text-xs text-slate-400 max-w-sm mx-auto mt-1">Try adjusting your search criteria or role filters.</p>
</div>

<!-- Add User Modal -->
<div id="addUserModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
  <div class="bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl p-6">
    <div class="flex items-center justify-between pb-4 border-b border-slate-100">
      <div>
        <h3 class="font-black text-lg text-slate-900">Add New User</h3>
        <p class="text-xs text-slate-500">Create a new user account.</p>
      </div>
      <button type="button" onclick="closeAddUserModal()" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center hover:bg-slate-200">
        <i data-feather="x" class="w-4 h-4"></i>
      </button>
    </div>

    <form method="post" class="space-y-4 mt-4">
      <input type="hidden" name="action" value="create">

      <div>
        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Full Name</label>
        <input type="text" name="fullname" required placeholder="e.g. John Santos" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs outline-none focus:border-purple-600 focus:ring-2 focus:ring-purple-100">
      </div>

      <div>
        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Email Address</label>
        <input type="email" name="email" required placeholder="john@example.com" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs outline-none focus:border-purple-600 focus:ring-2 focus:ring-purple-100">
      </div>

      <div>
        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Account Role</label>
        <select name="role" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs font-semibold outline-none focus:border-purple-600 bg-white">
          <option value="customer">Student / Customer</option>
          <option value="staff">Staff Desk Officer</option>
          <option value="admin">System Administrator</option>
        </select>
      </div>

      <div>
        <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Password</label>
        <input type="password" name="password" required minlength="8" maxlength="15" placeholder="8 to 15 characters" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs outline-none focus:border-purple-600 focus:ring-2 focus:ring-purple-100">
      </div>

      <div class="pt-3 flex justify-end gap-2 border-t border-slate-100">
        <button type="button" onclick="closeAddUserModal()" class="px-4 py-2.5 rounded-xl border border-slate-200 text-slate-600 text-xs font-semibold hover:bg-slate-50">Cancel</button>
        <button type="submit" class="px-5 py-2.5 rounded-xl bg-purple-600 text-white text-xs font-bold hover:bg-purple-700 shadow-md">Create User</button>
      </div>
    </form>
  </div>
</div>

<!-- Global Toast Container -->
<div class="toast-container" id="toastBox"></div>

<script>
  const filterPills = document.querySelectorAll('#filterContainer .filter-pill');
  const userRows = document.querySelectorAll('#usersTableBody .user-row');
  const userSearch = document.getElementById('userSearch');
  const emptyState = document.getElementById('emptyState');

  filterPills.forEach(pill => {
    pill.addEventListener('click', () => {
      filterPills.forEach(p => p.classList.remove('active'));
      pill.classList.add('active');
      filterUsers();
    });
  });

  if (userSearch) {
    userSearch.addEventListener('input', () => filterUsers());
  }

  function filterUsers() {
    const tableBody = document.getElementById('usersTableBody');
    const activeRole = document.querySelector('#filterContainer .filter-pill.active').getAttribute('data-role');
    const term = userSearch ? userSearch.value.trim().toLowerCase() : '';

    const applyFilter = () => {
      let visibleCount = 0;
      const currentRows = document.querySelectorAll('#usersTableBody .user-row');

      currentRows.forEach(row => {
        const role = row.getAttribute('data-role');
        const name = (row.getAttribute('data-name') || '').toLowerCase();
        const email = (row.getAttribute('data-email') || '').toLowerCase();

        const matchRole = (activeRole === 'all' || role === activeRole);
        const matchSearch = (!term || name.includes(term) || email.includes(term) || role.includes(term));

        if (matchRole && matchSearch) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      if (emptyState) {
        if (visibleCount === 0) {
          emptyState.classList.remove('hidden');
        } else {
          emptyState.classList.add('hidden');
        }
      }
      tableBody?.classList.remove('is-filtering');
    };

    if (!tableBody) {
      applyFilter();
      return;
    }
    tableBody.classList.add('is-filtering');
    window.setTimeout(applyFilter, 180);
  }

  function openAddUserModal() {
    document.getElementById('addUserModal').classList.remove('hidden');
  }

  function closeAddUserModal() {
    document.getElementById('addUserModal').classList.add('hidden');
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

  document.addEventListener('DOMContentLoaded', function() {
    if (typeof feather !== 'undefined') {
      feather.replace();
    }
    const queueMenu = document.getElementById('queueMenu');
    const queueSidebar = document.getElementById('queueSidebar');
    if (queueMenu && queueSidebar) {
      queueMenu.addEventListener('click', function() {
        queueSidebar.classList.toggle('is-open');
      });
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('created')) {
      showToast('User created successfully!');
    }
    if (urlParams.has('role_updated')) {
      showToast('User permission role updated successfully!');
    }
    if (urlParams.has('deleted')) {
      showToast('User deleted.');
    }
  });
</script>

<?php require_once '../includes/footer.php'; ?>
</div></main></div></body></html>
