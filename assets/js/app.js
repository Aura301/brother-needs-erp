// Dark mode toggle (disimpan di localStorage, dipakai lagi di kunjungan berikutnya)
function toggleTheme() {
  var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  if (isDark) {
    document.documentElement.removeAttribute('data-theme');
    localStorage.setItem('bn-theme', 'light');
  } else {
    document.documentElement.setAttribute('data-theme', 'dark');
    localStorage.setItem('bn-theme', 'dark');
  }
}

// Sidebar drawer buat layar kecil (HP/tablet)
function toggleSidebar(open) {
  var sidebar = document.querySelector('.sidebar');
  var overlay = document.getElementById('sidebar-overlay');
  if (!sidebar || !overlay) return;
  if (open) {
    sidebar.classList.add('open');
    overlay.classList.add('open');
  } else {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
  }
}
