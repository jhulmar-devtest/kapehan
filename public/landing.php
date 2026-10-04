<?php
// ============================================================
// public/landing.php  —  Public landing page
// Logged-in users are bounced straight to their dashboard.
// ============================================================
require_once __DIR__ . '/../config/init.php';
if (isLoggedIn()) redirectByRole();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= APP_NAME ?> — EARIST Cavite Campus</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800;1,9..40,300&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/../assets/css/variables.css">
  <style>
    /* ── Reset ─────────────────────────────────────────────── */
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html {
      font-size: 17.5px;
      scroll-behavior: smooth;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    /* ── Base ──────────────────────────────────────────────── */
    body {
      font-family: 'DM Sans', system-ui, sans-serif;
      background: var(--background-color);
      color: var(--text-color);
      min-height: 100vh;
      overflow-x: hidden;
      -webkit-font-smoothing: antialiased;
    }

    /* ── Noise texture overlay ─────────────────────────────── */
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.035'/%3E%3C/svg%3E");
      background-size: 200px 200px;
      pointer-events: none;
      z-index: 1000;
      opacity: 0.6;
    }

    /* ── Navbar ────────────────────────────────────────────── */
    .nav {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 100;
      padding: var(--space-5) 48px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      transition: background var(--transition-slow), padding var(--transition-slow);
    }

    .nav.scrolled {
      background: rgba(243, 240, 236, 0.94);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      padding: var(--space-3) 48px;
      border-bottom: 1px solid var(--border-color);
    }

    .nav-logo {
      display: flex;
      align-items: center;
      gap: var(--space-3);
    }

    .nav-logo-icon {
      width: 44px;
      height: 44px;
      background: var(--primary-color);
      border-radius: var(--radius-full);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: var(--shadow-primary);
      flex-shrink: 0;
      overflow: hidden;
    }

    .nav-logo-icon img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .nav-logo-text {
      font-size: 0.88rem;
      font-weight: 700;
      color: var(--text-color);
      letter-spacing: -0.01em;
      line-height: 1.2;
    }

    .nav-logo-sub {
      font-size: 0.64rem;
      color: var(--text-muted);
      font-weight: 400;
      letter-spacing: 0.04em;
    }

    .nav-actions {
      display: flex;
      align-items: center;
      gap: var(--space-2);
    }

    .btn-nav-ghost,
    .btn-nav-primary {
      height: 42px;
      padding: 0 var(--space-4);
      border-radius: var(--radius-sm);
      font-size: 0.88rem;
      font-weight: 600;
      font-family: inherit;
      cursor: pointer;
      transition: all var(--transition-base);
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
    }

    .btn-nav-ghost {
      border: 1.5px solid var(--border-color);
      background: transparent;
      color: var(--text-color);
    }

    .btn-nav-ghost:hover {
      background: var(--secondary-subtle);
      border-color: var(--border-strong);
    }

    .btn-nav-primary {
      border: none;
      background: var(--primary-color);
      color: var(--text-on-primary);
      box-shadow: var(--shadow-primary);
    }

    .btn-nav-primary:hover {
      background: var(--primary-dark);
      transform: translateY(-1px);
      box-shadow: 0 6px 20px var(--primary-glow);
    }

    /* ── Hero ──────────────────────────────────────────────── */
    .hero {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 120px 48px 80px;
      position: relative;
      overflow: hidden;
    }

    .hero::after {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -60%);
      width: 700px;
      height: 700px;
      background: radial-gradient(ellipse, var(--primary-subtle) 0%, transparent 70%);
      pointer-events: none;
    }

    .hero-ring {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: 560px;
      height: 560px;
      border-radius: var(--radius-full);
      border: 1px solid var(--primary-glow);
      pointer-events: none;
      animation: ringPulse 6s ease-in-out infinite;
    }

    .hero-ring-2 {
      width: 750px;
      height: 750px;
      border-color: var(--primary-subtle);
      animation-delay: 2s;
    }

    @keyframes ringPulse {

      0%,
      100% {
        opacity: 0.6;
        transform: translate(-50%, -50%) scale(1);
      }

      50% {
        opacity: 1;
        transform: translate(-50%, -50%) scale(1.03);
      }
    }

    .hero-inner {
      position: relative;
      z-index: 2;
      text-align: center;
      max-width: 800px;
    }

    .hero-tag {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      background: var(--primary-subtle);
      border: 1px solid var(--primary-glow);
      border-radius: var(--radius-full);
      padding: 5px var(--space-4);
      font-size: 0.72rem;
      font-weight: 600;
      color: var(--accent-dark);
      letter-spacing: 0.06em;
      text-transform: uppercase;
      margin-bottom: var(--space-7);
      opacity: 0;
      animation: fadeUp 0.6s 0.1s forwards;
    }

    .hero-headline {
      font-family: 'Instrument Serif', serif;
      font-size: clamp(2.8rem, 6vw, 5rem);
      font-weight: 400;
      line-height: 1.1;
      letter-spacing: -0.02em;
      color: var(--text-color);
      margin-bottom: var(--space-6);
      opacity: 0;
      animation: fadeUp 0.7s 0.2s forwards;
    }

    .hero-headline em {
      font-style: italic;
      color: var(--primary-color);
    }

    .hero-headline .accent-word {
      position: relative;
      display: inline-block;
    }

    .hero-headline .accent-word::after {
      content: '';
      position: absolute;
      bottom: 4px;
      left: 0;
      right: 0;
      height: 3px;
      background: var(--accent-color);
      border-radius: var(--radius-xs);
      transform: scaleX(0);
      transform-origin: left;
      animation: drawLine 0.6s 0.9s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    }

    @keyframes drawLine {
      to {
        transform: scaleX(1);
      }
    }

    .hero-sub {
      font-size: 1.05rem;
      color: var(--text-secondary);
      line-height: 1.7;
      max-width: 520px;
      margin: 0 auto var(--space-10);
      font-weight: 300;
      opacity: 0;
      animation: fadeUp 0.7s 0.35s forwards;
    }

    .hero-ctas {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: var(--space-3);
      flex-wrap: wrap;
      opacity: 0;
      animation: fadeUp 0.7s 0.5s forwards;
    }

    .btn-hero-primary,
    .btn-hero-ghost {
      height: 48px;
      padding: 0 var(--space-7);
      border-radius: var(--radius-md);
      font-size: 0.90rem;
      font-weight: 700;
      font-family: inherit;
      cursor: pointer;
      transition: all var(--transition-base);
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
    }

    .btn-hero-primary {
      border: none;
      background: var(--primary-color);
      color: var(--text-on-primary);
      box-shadow: 0 6px 24px var(--primary-glow);
    }

    .btn-hero-primary:hover {
      background: var(--primary-dark);
      transform: translateY(-2px);
      box-shadow: 0 10px 32px rgba(192, 57, 43, 0.55);
    }

    .btn-hero-ghost {
      border: 1.5px solid var(--border-color);
      background: transparent;
      color: var(--text-color);
    }

    .btn-hero-ghost:hover {
      background: var(--secondary-subtle);
      border-color: var(--border-strong);
    }

    .hero-scroll {
      position: absolute;
      bottom: 36px;
      left: 50%;
      transform: translateX(-50%);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: var(--space-2);
      color: var(--text-placeholder);
      font-size: 0.68rem;
      font-weight: 500;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      opacity: 0;
      animation: fadeIn 1s 1.2s forwards;
    }

    .hero-scroll-dot {
      width: 1px;
      height: 40px;
      background: linear-gradient(to bottom, var(--text-placeholder), transparent);
      animation: scrollDot 2s ease-in-out infinite;
    }

    @keyframes scrollDot {

      0%,
      100% {
        transform: scaleY(1);
        opacity: 0.6;
      }

      50% {
        transform: scaleY(0.6);
        opacity: 1;
      }
    }

    /* ── Section Shared ────────────────────────────────────── */
    .section {
      padding: 96px 48px;
      position: relative;
    }

    .section-inner {
      max-width: 1100px;
      margin: 0 auto;
    }

    .section-label {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      font-size: 0.70rem;
      font-weight: 700;
      color: var(--primary-color);
      text-transform: uppercase;
      letter-spacing: 0.12em;
      margin-bottom: var(--space-4);
    }

    .section-label::before {
      content: '';
      width: 20px;
      height: 1.5px;
      background: var(--primary-color);
      border-radius: var(--radius-xs);
    }

    .section-title {
      font-family: 'Instrument Serif', serif;
      font-size: clamp(1.8rem, 3.5vw, 2.8rem);
      font-weight: 400;
      line-height: 1.2;
      color: var(--text-color);
      margin-bottom: var(--space-4);
      letter-spacing: -0.02em;
    }

    .section-title em {
      font-style: italic;
      color: var(--primary-color);
    }

    .section-sub {
      font-size: 0.95rem;
      color: var(--text-secondary);
      line-height: 1.7;
      max-width: 480px;
      margin-bottom: 56px;
      font-weight: 300;
    }

    /* ── Steps ─────────────────────────────────────────────── */
    .steps-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 1px;
      background: var(--border-color);
      border-radius: var(--radius-lg);
      overflow: hidden;
    }

    .step-card {
      background: var(--surface-color);
      padding: 36px var(--space-8);
      position: relative;
      transition: background var(--transition-base);
    }

    .step-card:hover {
      background: var(--surface-raised);
    }

    .step-number {
      font-family: 'Instrument Serif', serif;
      font-size: 4rem;
      font-weight: 400;
      color: var(--primary-subtle);
      line-height: 1;
      position: absolute;
      top: var(--space-5);
      right: var(--space-6);
      transition: color var(--transition-base);
    }

    .step-card:hover .step-number {
      color: var(--primary-glow);
    }

    .step-icon {
      width: 44px;
      height: 44px;
      background: var(--primary-subtle);
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      color: var(--primary-color);
      margin-bottom: var(--space-5);
      transition: background var(--transition-base), transform var(--transition-base);
    }

    .step-card:hover .step-icon {
      background: var(--primary-color);
      color: var(--text-on-primary);
      transform: scale(1.08);
    }

    .step-title {
      font-size: 1.0rem;
      font-weight: 700;
      color: var(--text-color);
      margin-bottom: var(--space-2);
      letter-spacing: -0.01em;
    }

    .step-desc {
      font-size: 0.84rem;
      color: var(--text-secondary);
      line-height: 1.65;
      font-weight: 300;
    }

    /* ── Features Section ──────────────────────────────────── */
    .features-section {
      padding: 80px 48px;
      background: var(--surface-raised);
      border-top: 1px solid var(--border-color);
      border-bottom: 1px solid var(--border-color);
    }

    .features-layout {
      max-width: 1100px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 80px;
      align-items: center;
    }

    .features-list {
      display: flex;
      flex-direction: column;
      gap: var(--space-7);
      margin-top: 48px;
    }

    .feature-item {
      display: flex;
      gap: var(--space-4);
      align-items: flex-start;
    }

    .feature-icon {
      width: 38px;
      height: 38px;
      background: var(--primary-subtle);
      border: 1px solid var(--primary-glow);
      border-radius: var(--radius-sm);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 15px;
      color: var(--primary-color);
      flex-shrink: 0;
      margin-top: 2px;
    }

    .feature-title {
      font-size: 0.92rem;
      font-weight: 700;
      color: var(--text-color);
      margin-bottom: var(--space-1);
    }

    .feature-desc {
      font-size: 0.82rem;
      color: var(--text-secondary);
      line-height: 1.6;
      font-weight: 300;
    }

    /* ── Visual Side / Card Stack ──────────────────────────── */
    .features-visual {
      position: relative;
    }

    .features-card-stack {
      position: relative;
      height: 340px;
    }

    .fcard {
      position: absolute;
      background: var(--surface-color);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      padding: 22px var(--space-6);
      box-shadow: var(--shadow-lg);
    }

    .fcard-main {
      width: 300px;
      top: 0;
      left: 50%;
      transform: translateX(-50%);
      z-index: 3;
    }

    .fcard-back {
      width: 270px;
      top: 20px;
      left: 50%;
      transform: translateX(-50%) rotate(4deg);
      z-index: 1;
      opacity: 0.65;
    }

    .fcard-back2 {
      width: 270px;
      top: 20px;
      left: 50%;
      transform: translateX(-50%) rotate(-4deg);
      z-index: 2;
      opacity: 0.80;
    }

    .fcard-label {
      font-size: 0.66rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: var(--text-muted);
      margin-bottom: var(--space-4);
    }

    .fcard-order-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: var(--space-2) 0;
      border-bottom: 1px solid var(--border-color);
      font-size: 0.82rem;
    }

    .fcard-order-item:last-child {
      border-bottom: none;
    }

    .fcard-order-name {
      color: var(--text-color);
      font-weight: 500;
    }

    .fcard-order-qty {
      color: var(--text-muted);
      margin: 0 var(--space-2);
    }

    .fcard-order-price {
      color: var(--accent-dark);
      font-weight: 700;
    }

    .fcard-total {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: var(--space-4);
      padding-top: var(--space-4);
      border-top: 1px solid var(--border-color);
    }

    .fcard-total-label {
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }

    .fcard-total-amount {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--text-color);
    }

    .fcard-status {
      display: inline-flex;
      align-items: center;
      gap: var(--space-1);
      background: var(--status-ready-bg);
      border: 1px solid var(--status-ready-border);
      border-radius: var(--radius-full);
      padding: 4px var(--space-3);
      font-size: 0.68rem;
      font-weight: 700;
      color: var(--status-ready);
      margin-top: var(--space-4);
    }

    .fcard-status::before {
      content: '';
      width: 6px;
      height: 6px;
      border-radius: var(--radius-full);
      background: var(--status-ready);
      animation: blink 1.5s ease-in-out infinite;
    }

    @keyframes blink {

      0%,
      100% {
        opacity: 1;
      }

      50% {
        opacity: 0.3;
      }
    }

    /* ── Roles ─────────────────────────────────────────────── */
    .roles-section {
      padding: 96px 48px;
    }

    .roles-grid {
      max-width: 1100px;
      margin: 52px auto 0;
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: var(--space-4);
    }

    .role-card {
      background: var(--surface-color);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      padding: 36px var(--space-7);
      transition: border-color var(--transition-base), transform var(--transition-base), box-shadow var(--transition-base);
      position: relative;
      overflow: hidden;
      display: block;
    }

    .role-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 2px;
      background: transparent;
      transition: background var(--transition-base);
    }

    .role-card:hover {
      border-color: var(--primary-light);
      transform: translateY(-4px);
      box-shadow: var(--shadow-lg);
    }

    .role-card:hover::before {
      background: var(--primary-color);
    }

    .role-card-icon {
      width: 52px;
      height: 52px;
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      margin-bottom: var(--space-6);
    }

    .role-card-icon.admin {
      background: var(--primary-subtle);
      color: var(--primary-color);
    }

    .role-card-icon.cashier {
      background: var(--secondary-subtle);
      color: var(--secondary-light);
    }

    .role-card-icon.student {
      background: var(--accent-subtle);
      color: var(--accent-dark);
    }

    .role-card-title {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--text-color);
      margin-bottom: var(--space-2);
      letter-spacing: -0.01em;
    }

    .role-card-desc {
      font-size: 0.82rem;
      color: var(--text-secondary);
      line-height: 1.65;
      margin-bottom: var(--space-6);
      font-weight: 300;
    }

    .role-card-link {
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--primary-color);
      display: inline-flex;
      align-items: center;
      gap: var(--space-1);
      transition: gap var(--transition-base);
    }

    .role-card:hover .role-card-link {
      gap: var(--space-2);
    }

    /* ── Footer ────────────────────────────────────────────── */
    .footer {
      border-top: 1px solid var(--border-color);
      padding: var(--space-10) 48px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: var(--space-5);
      flex-wrap: wrap;
    }

    .footer-brand {
      display: flex;
      align-items: center;
      gap: var(--space-2);
    }

    .footer-brand-icon {
      width: 30px;
      height: 30px;
      background: var(--primary-color);
      border-radius: var(--radius-full);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }

    .footer-brand-icon img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .footer-brand-name {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-color);
    }

    .footer-brand-sub {
      font-size: 0.68rem;
      color: var(--text-muted);
    }

    .footer-copy {
      font-size: 0.74rem;
      color: var(--text-placeholder);
    }

    .footer-links {
      display: flex;
      gap: var(--space-5);
    }

    .footer-links a {
      font-size: 0.78rem;
      color: var(--text-muted);
      transition: color var(--transition-fast);
    }

    .footer-links a:hover {
      color: var(--text-color);
    }

    /* ── Animations ────────────────────────────────────────── */
    @keyframes fadeUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes fadeIn {
      from {
        opacity: 0;
      }

      to {
        opacity: 1;
      }
    }

    .reveal {
      opacity: 0;
      transform: translateY(24px);
      transition: opacity 0.65s ease, transform 0.65s ease;
    }

    .reveal.visible {
      opacity: 1;
      transform: translateY(0);
    }

    .reveal-delay-1 {
      transition-delay: 0.1s;
    }

    .reveal-delay-2 {
      transition-delay: 0.2s;
    }

    .reveal-delay-3 {
      transition-delay: 0.3s;
    }

    /* ── Responsive ────────────────────────────────────────── */
    @media (max-width: 900px) {

      .nav,
      .nav.scrolled {
        padding: var(--space-4) var(--space-6);
      }

      .hero,
      .section,
      .features-section,
      .roles-section {
        padding: 64px var(--space-6);
      }

      .features-layout {
        grid-template-columns: 1fr;
        gap: var(--space-10);
      }

      .features-visual {
        display: none;
      }

      .steps-grid,
      .roles-grid {
        grid-template-columns: 1fr;
        gap: var(--space-2);
        background: transparent;
      }

      .step-card {
        border-radius: var(--radius-md);
        border: 1px solid var(--border-color);
      }

      .footer {
        padding: var(--space-8) var(--space-6);
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-4);
      }
    }

    @media (max-width: 540px) {
      .hero-headline {
        font-size: 2.2rem;
      }

      .btn-nav {
        display: none;
      }

      .hero-ctas {
        flex-direction: column;
        width: 100%;
      }

      .btn-hero-primary,
      .btn-hero-ghost {
        width: 100%;
        justify-content: center;
      }
    }
  </style>
</head>

<body>

  <nav class="nav" id="navbar">
    <a href="#" class="nav-logo">
      <div class="nav-logo-icon"><img src="../assets/images/logo.png" alt="Logo"></div>
      <div>
        <div class="nav-logo-text"><?= APP_NAME ?></div>
        <div class="nav-logo-sub">EARIST Cavite Campus</div>
      </div>
    </a>
    <div class="nav-actions">
      <a href="<?= APP_URL ?>/menu.php" class="btn-nav-ghost">
        <i class="fa-solid fa-list"></i> <span class="nav-text-hide">Menu</span>
      </a>
      <a href="<?= APP_URL ?>/register.php" class="btn-nav btn-nav-ghost">
        <i class="fa-solid fa-user-plus"></i> <span class="nav-text-hide">Register</span>
      </a>
      <a href="<?= APP_URL ?>/login.php" class="btn-nav btn-nav-primary">
        <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In
      </a>
    </div>
  </nav>

  <section class="hero" id="hero">
    <div class="hero-ring"></div>
    <div class="hero-ring hero-ring-2"></div>
    <div class="hero-inner">
      <div class="hero-tag"><i class="fa-solid fa-location-dot"></i> EARIST Cavite Campus — Open Daily</div>
      <h1 class="hero-headline">Your <em>campus <span class="accent-word">coffee</span></em>,<br>ordered your way</h1>
      <p class="hero-sub">Skip the queue. Pre-order your favorite drinks and snacks online, pay securely, and pick up fresh — all without the wait.</p>
      <div class="hero-ctas">
        <a href="<?= APP_URL ?>/register.php" class="btn-hero-primary"><i class="fa-solid fa-user-plus"></i> Get Started — It's Free</a>
        <a href="<?= APP_URL ?>/login.php" class="btn-hero-ghost"><i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In</a>
      </div>
    </div>
    <div class="hero-scroll">
      <div class="hero-scroll-dot"></div>Scroll
    </div>
  </section>

  <section class="section" id="how">
    <div class="section-inner">
      <div class="reveal">
        <div class="section-label">How it works</div>
        <h2 class="section-title">Three steps to your<br><em>perfect order</em></h2>
        <p class="section-sub">No more lining up between classes. Order ahead, pay online, and just walk in to collect.</p>
      </div>
      <div class="steps-grid">
        <div class="step-card reveal reveal-delay-1">
          <div class="step-number">01</div>
          <div class="step-icon"><i class="fa-solid fa-list"></i></div>
          <div class="step-title">Browse the Menu</div>
          <div class="step-desc">Explore our full menu of hot drinks, cold brews, and snacks. Add your favorites to your cart in seconds.</div>
        </div>
        <div class="step-card reveal reveal-delay-2">
          <div class="step-number">02</div>
          <div class="step-icon"><i class="fa-solid fa-mobile-screen"></i></div>
          <div class="step-title">Pay Online</div>
          <div class="step-desc">Confirm your order with GCash, PayMaya, or online banking. Your order is locked in instantly.</div>
        </div>
        <div class="step-card reveal reveal-delay-3">
          <div class="step-number">03</div>
          <div class="step-icon"><i class="fa-solid fa-id-card"></i></div>
          <div class="step-title">Show ID & Collect</div>
          <div class="step-desc">Walk to the counter when your order is ready, show your school ID, and pick it up — no waiting in line.</div>
        </div>
      </div>
    </div>
  </section>

  <section class="features-section" id="features">
    <div class="features-layout">
      <div>
        <div class="reveal">
          <div class="section-label">Why use it</div>
          <h2 class="section-title">Built for<br><em>busy students</em></h2>
        </div>
        <div class="features-list">
          <div class="feature-item reveal reveal-delay-1">
            <div class="feature-icon"><i class="fa-solid fa-bolt"></i></div>
            <div>
              <div class="feature-title">Zero wait time</div>
              <div class="feature-desc">Your order is ready by the time you arrive. No standing around between lectures.</div>
            </div>
          </div>
          <div class="feature-item reveal reveal-delay-2">
            <div class="feature-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div>
              <div class="feature-title">Full order history</div>
              <div class="feature-desc">Track every order you've placed — status updates in real time from pending to ready.</div>
            </div>
          </div>
          <div class="feature-item reveal reveal-delay-3">
            <div class="feature-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div>
              <div class="feature-title">Secure & campus-only</div>
              <div class="feature-desc">Enrollment verified at pickup via school ID. Your account and payments are fully secured.</div>
            </div>
          </div>
          <div class="feature-item reveal">
            <div class="feature-icon"><i class="fa-solid fa-rotate-left"></i></div>
            <div>
              <div class="feature-title">Easy refunds</div>
              <div class="feature-desc">Something wrong? Submit a refund request directly from your order history page.</div>
            </div>
          </div>
        </div>
      </div>
      <div class="features-visual reveal">
        <div class="features-card-stack">
          <div class="fcard fcard-back"></div>
          <div class="fcard fcard-back2"></div>
          <div class="fcard fcard-main">
            <div class="fcard-label">Your Order — #ECC-20250001</div>
            <div class="fcard-order-item"><span class="fcard-order-name">Café Latte</span><span class="fcard-order-qty">×2</span><span class="fcard-order-price">₱120.00</span></div>
            <div class="fcard-order-item"><span class="fcard-order-name">Iced Americano</span><span class="fcard-order-qty">×1</span><span class="fcard-order-price">₱65.00</span></div>
            <div class="fcard-order-item"><span class="fcard-order-name">Cheese Pandesal</span><span class="fcard-order-qty">×2</span><span class="fcard-order-price">₱40.00</span></div>
            <div class="fcard-total"><span class="fcard-total-label">Total Paid</span><span class="fcard-total-amount">₱225.00</span></div>
            <div class="fcard-status">Ready for pickup</div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="roles-section">
    <div class="section-inner">
      <div class="reveal" style="max-width:500px">
        <div class="section-label">Access levels</div>
        <h2 class="section-title">One system,<br><em>three roles</em></h2>
        <p class="section-sub" style="margin-bottom:0">Whether you're a student ordering ahead, a cashier managing the counter, or an admin overseeing everything — there's a tailored experience for you.</p>
      </div>
      <div class="roles-grid">
        <a href="<?= APP_URL ?>/login.php" class="role-card reveal reveal-delay-1">
          <div class="role-card-icon student"><i class="fa-solid fa-graduation-cap"></i></div>
          <div class="role-card-title">Student</div>
          <div class="role-card-desc">Browse the menu, pre-order from anywhere on campus, pay online, and track your order status in real time.</div>
          <div class="role-card-link">Sign in as Student <i class="fa-solid fa-arrow-right"></i></div>
        </a>
        <a href="<?= APP_URL ?>/cashier-select.php" class="role-card reveal reveal-delay-2">
          <div class="role-card-icon cashier"><i class="fa-solid fa-cash-register"></i></div>
          <div class="role-card-title">Cashier</div>
          <div class="role-card-desc">Handle walk-in orders on the POS, manage pre-order queues, process payments, and print receipts.</div>
          <div class="role-card-link">Sign in as Cashier <i class="fa-solid fa-arrow-right"></i></div>
        </a>
        <a href="<?= APP_URL ?>/staff-login.php" class="role-card reveal reveal-delay-3">
          <div class="role-card-icon admin"><i class="fa-solid fa-gauge-high"></i></div>
          <div class="role-card-title">Admin</div>
          <div class="role-card-desc">Manage products, cashier accounts, view sales reports, approve refunds, and oversee all orders.</div>
          <div class="role-card-link">Sign in as Admin <i class="fa-solid fa-arrow-right"></i></div>
        </a>
      </div>
    </div>
  </section>

  <footer class="footer">
    <div class="footer-brand">
      <div class="footer-brand-icon"><img src="../assets/images/logo.png" alt="Logo"></div>
      <div>
        <div class="footer-brand-name"><?= APP_NAME ?></div>
        <div class="footer-brand-sub">EARIST Cavite Campus</div>
      </div>
    </div>
    <div class="footer-copy">&copy; <?= date('Y') ?> EARIST Cavite Campus. All rights reserved.</div>
    <div class="footer-links">
      <a href="<?= APP_URL ?>/login.php">Sign In</a>
      <a href="<?= APP_URL ?>/register.php">Register</a>
    </div>
  </footer>

  <script>
    const navbar = document.getElementById('navbar');
    window.addEventListener('scroll', () => {
      navbar.classList.toggle('scrolled', window.scrollY > 40);
    }, {
      passive: true
    });

    const revealEls = document.querySelectorAll('.reveal');
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          observer.unobserve(entry.target);
        }
      });
    }, {
      threshold: 0.12,
      rootMargin: '0px 0px -40px 0px'
    });
    revealEls.forEach(el => observer.observe(el));
  </script>
</body>

</html>