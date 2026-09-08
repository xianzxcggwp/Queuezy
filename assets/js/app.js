(() => {
  const state = { screen: 'home', department: 'Registrar', service: 'Certificate of Enrollment', ticket: 'R-032', position: 4, billing: 'Pending' };
  const services = { Registrar: [['Certificate of Enrollment', 15], ['TOR Official', 20], ['Good Moral Certificate', 10], ['Document Authentication', 25]], Cashier: [['Tuition Payment', 10], ['Assessment Request', 15], ['Refund Processing', 20], ['Payment Inquiry', 8]], Guidance: [['Student Consultation', 10], ['Career Counseling', 20], ['Wellness Check-in', 15], ['Referral Request', 12]], Library: [['Library Card', 8], ['Book Borrowing', 5], ['Research Assistance', 15], ['Clearance Request', 10]], 'Other Services': [['General Inquiry', 10], ['Document Request', 15], ['Appointment Request', 20], ['Technical Support', 12]] };
  const all = (selector) => [...document.querySelectorAll(selector)];
  const setText = (selector, value) => all(selector).forEach((element) => { element.textContent = value; });
  function render() {
    setText('#ticketNumber', state.ticket); setText('#homeTicket', state.ticket); setText('#activeDepartment', state.department);
    setText('#ticketPosition', state.position); setText('#homePosition', state.position); setText('#trackingPosition', state.position); setText('#ticketWait', `${state.position * 5} min`);
    const status = document.querySelector('#billingStatus'); if (status) { status.textContent = state.billing; status.className = `status ${state.billing === 'Paid' ? 'green' : 'amber'}`; }
  }
  function show(screen) { state.screen = screen; all('[data-screen]').forEach((element) => { const active = element.dataset.screen === screen; element.classList.toggle('active', active); element.classList.toggle('hidden-view', !active); }); const navScreen = screen === 'service' ? 'department' : screen; all('.customer-sidebar [data-go]').forEach((link) => link.classList.toggle('active', link.dataset.go === navScreen)); document.querySelector('#customerSidebar')?.classList.remove('open'); render(); }
  function updateServices() { (services[state.department] || services.Registrar).forEach((item, index) => { const button = all('[data-screen="service"] [data-service]')[index]; if (!button) return; button.dataset.service = item[0]; const name = button.querySelector('b'); const wait = button.querySelector('small'); if (name) name.textContent = item[0]; if (wait) wait.textContent = `Estimated wait: ${item[1]} minutes`; }); }
  async function createQueue() {
    const body = new URLSearchParams({ department: state.department, service: state.service });
    try { const response = await fetch('api/join_queue.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body }); const result = await response.json(); if (result.ok && result.ticket) state.ticket = result.ticket; } catch (error) { console.warn('Queue persistence unavailable.', error); }
    show('ticket');
  }
  document.addEventListener('DOMContentLoaded', () => {
    const welcome = document.querySelector('[data-screen="home"] h1');
    if (welcome && window.queuezyUserName) {
      welcome.innerHTML = 'Welcome, <span class="text-queue"></span>!';
      welcome.querySelector('span').textContent = window.queuezyUserName;
    }
    document.addEventListener('click', (event) => {
      const destination = event.target.closest('[data-go]')?.dataset.go; if (destination) { event.preventDefault(); show(destination); return; }
      const department = event.target.closest('[data-department]'); if (department) { state.department = department.dataset.department; updateServices(); show('service'); return; }
      const service = event.target.closest('[data-service]'); if (service) { state.service = service.dataset.service; createQueue(); return; }
      if (event.target.closest('[data-action="announce"]')) QueuezyAnnouncer.speak(state.ticket);
      if (event.target.closest('[data-action="paid"]')) { state.billing = 'Paid'; render(); }
      if (event.target.closest('[data-action="cancel"]') && confirm('Cancel your active queue ticket?')) { state.billing = 'Cancelled'; state.position = 0; state.ticket = 'No active ticket'; render(); show('home'); }
    });
    show('home');
  });
})();
