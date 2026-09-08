<footer class="queue-footer"><span></span><span></span></footer>
<div class="logout-modal" id="logoutModal" aria-hidden="true">
	<div class="logout-modal__panel" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle">
		<div class="logout-modal__icon"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i></div>
		<h2 id="logoutModalTitle">Log out?</h2>
		<p>Are you sure you want to end your Queuezy session?</p>
		<div class="logout-modal__actions">
			<button type="button" class="logout-modal__cancel" id="logoutCancel">Cancel</button>
			<a class="logout-modal__confirm" id="logoutConfirm" href="logout.php">Log Out</a>
		</div>
	</div>
</div>
<script>
window.toggleQueueSidebar = function (event) {
	if (event) event.preventDefault();
	const layout = document.querySelector('.queue-layout');
	const sidebar = document.getElementById('queueSidebar');
	if (!layout || !sidebar) return;
	if (window.innerWidth <= 900) {
		sidebar.classList.toggle('is-open');
		sidebar.classList.remove('is-collapsed');
	} else {
		const collapsed = !sidebar.classList.contains('is-collapsed');
		sidebar.classList.toggle('is-collapsed', collapsed);
		layout.classList.toggle('sidebar-collapsed', collapsed);
	}
};
document.addEventListener('DOMContentLoaded', function () {
	if (typeof feather !== 'undefined') feather.replace();
	document.querySelectorAll('a[href]').forEach(function (link) {
		link.addEventListener('click', function (event) {
			const href = link.getAttribute('href') || '';
			if (event.defaultPrevented || href.startsWith('#') || href.startsWith('javascript:') || link.target === '_blank' || link.closest('.logout-modal')) return;
			document.body.classList.add('portal-leaving');
		});
	});
	document.querySelectorAll('form').forEach(function (form) {
		form.addEventListener('submit', function (event) {
			if (event.defaultPrevented || form.dataset.noTransition === 'true' || (form.method || 'get').toLowerCase() === 'get') return;
			document.body.classList.add('portal-leaving');
		});
	});
	const layout = document.querySelector('.queue-layout');
	const sidebar = document.getElementById('queueSidebar');
	if (!layout || !sidebar) return;
	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			sidebar.classList.remove('is-open');
			layout.classList.remove('sidebar-collapsed');
		}
	});
	const logoutModal = document.getElementById('logoutModal');
	const logoutCancel = document.getElementById('logoutCancel');
	const logoutConfirm = document.getElementById('logoutConfirm');
	const logoutLinks = document.querySelectorAll('a[href="logout.php"]:not(.logout-alert), a[href="./logout.php"]:not(.logout-alert), a[href="../logout.php"]:not(.logout-alert), .logout-confirm');

	const showLogoutModal = function (targetUrl) {
		logoutConfirm.href = targetUrl || '../logout.php';
		logoutModal.classList.add('is-visible');
		logoutModal.setAttribute('aria-hidden', 'false');
		document.documentElement.classList.add('logout-modal-open');
		document.body.classList.add('logout-modal-open');
	};

	logoutLinks.forEach(function (link) {
		link.addEventListener('click', function (event) {
			event.preventDefault();
			showLogoutModal(link.getAttribute('href') || '../logout.php');
		});
	});

	logoutConfirm?.addEventListener('click', function (event) {
		event.preventDefault();
		window.location.href = logoutConfirm.getAttribute('href') || '../logout.php';
	});

	logoutCancel?.addEventListener('click', function () {
		logoutModal?.classList.remove('is-visible');
		logoutModal?.setAttribute('aria-hidden', 'true');
		document.documentElement.classList.remove('logout-modal-open');
		document.body.classList.remove('logout-modal-open');
	});

	logoutModal?.addEventListener('click', function (event) {
		if (event.target === logoutModal) logoutCancel.click();
	});
});
</script>
