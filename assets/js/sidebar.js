// assets/js/sidebar.js
// Handles sidebar collapse (desktop) and drawer (mobile).

/* ── Desktop: collapse / expand ─────────────────────────── */
function toggleSidebar() {
  if (window.innerWidth <= 768) {
    // On mobile, use the drawer instead
    openMobileSidebar();
    return;
  }
  const sidebar = document.getElementById("sidebar");
  const wrapper = document.getElementById("main-wrapper");
  sidebar.classList.toggle("collapsed");
  wrapper.classList.toggle("collapsed");
  const isCollapsed = sidebar.classList.contains("collapsed");
  localStorage.setItem("sidebarCollapsed", isCollapsed ? "1" : "0");
}

/* ── Mobile: drawer open / close ────────────────────────── */
function openMobileSidebar() {
  const sidebar = document.getElementById("sidebar");
  const backdrop = document.getElementById("sidebar-backdrop");
  sidebar.classList.add("mobile-open");
  if (backdrop) {
    backdrop.style.display = "block";
    requestAnimationFrame(() => backdrop.classList.add("visible"));
  }
  document.body.style.overflow = "hidden";
}

function closeMobileSidebar() {
  const sidebar = document.getElementById("sidebar");
  const backdrop = document.getElementById("sidebar-backdrop");
  sidebar.classList.remove("mobile-open");
  if (backdrop) {
    backdrop.classList.remove("visible");
    setTimeout(() => {
      backdrop.style.display = "none";
    }, 200);
  }
  document.body.style.overflow = "";
}

/* ── Close drawer when a nav link is tapped on mobile ───── */
document.addEventListener("DOMContentLoaded", function () {
  // Restore desktop sidebar state
  if (
    window.innerWidth > 768 &&
    localStorage.getItem("sidebarCollapsed") === "1"
  ) {
    const sidebar = document.getElementById("sidebar");
    const wrapper = document.getElementById("main-wrapper");
    if (sidebar) sidebar.classList.add("collapsed");
    if (wrapper) wrapper.classList.add("collapsed");
  }

  // Backdrop click closes drawer
  const backdrop = document.getElementById("sidebar-backdrop");
  if (backdrop) {
    backdrop.addEventListener("click", closeMobileSidebar);
  }

  // Nav item click closes drawer on mobile
  document.querySelectorAll(".nav-item").forEach(function (item) {
    item.addEventListener("click", function () {
      if (window.innerWidth <= 768) closeMobileSidebar();
    });
  });

  // Swipe-left on sidebar closes it (touch devices)
  const sidebar = document.getElementById("sidebar");
  if (sidebar) {
    let touchStartX = 0;
    sidebar.addEventListener(
      "touchstart",
      function (e) {
        touchStartX = e.changedTouches[0].clientX;
      },
      { passive: true },
    );
    sidebar.addEventListener(
      "touchend",
      function (e) {
        const deltaX = e.changedTouches[0].clientX - touchStartX;
        if (deltaX < -60) closeMobileSidebar(); // swipe left 60px
      },
      { passive: true },
    );
  }

  // Close drawer on resize to desktop
  window.addEventListener("resize", function () {
    if (window.innerWidth > 768) closeMobileSidebar();
  });
});
