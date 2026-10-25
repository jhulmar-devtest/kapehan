<div class="modal-overlay hidden" id="register-reading-modal" role="presentation" aria-hidden="true">
  <section class="modal reading-modal" role="dialog" aria-modal="true" aria-labelledby="register-reading-modal-title">
    <div class="modal-header">
      <div class="modal-title" id="register-reading-modal-title"><i class="fa-solid fa-receipt"></i> Register reading</div>
      <button type="button" class="modal-close" id="register-reading-modal-close" aria-label="Close reading"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="reading-modal-content">
      <iframe id="register-reading-frame" title="Register reading details" src="about:blank"></iframe>
    </div>
    <div class="modal-footer">
      <a id="register-reading-print" class="btn btn-outline" href="#" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i> Print / Save PDF</a>
      <button type="button" class="btn btn-ghost" id="register-reading-modal-done">Close</button>
    </div>
  </section>
</div>
<style>
  .reading-modal {
    width: min(820px, 100%);
    overflow: hidden
  }

  .reading-modal-content {
    height: min(70vh, 680px);
    min-height: 360px;
    background: #fff
  }

  .reading-modal-content iframe {
    display: block;
    width: 100%;
    height: 100%;
    border: 0
  }

  @media(max-width:600px) {
    #register-reading-modal {
      padding: 8px
    }

    .reading-modal {
      height: calc(100vh - 16px);
      max-height: none;
      border-radius: 12px
    }

    .reading-modal-content {
      height: auto;
      min-height: 0;
      flex: 1
    }

    .reading-modal {
      display: flex;
      flex-direction: column
    }
  }
</style>
<script>
  (() => {
    const modal = document.getElementById('register-reading-modal');
    if (!modal) return;
    const frame = document.getElementById('register-reading-frame');
    const title = document.getElementById('register-reading-modal-title');
    const print = document.getElementById('register-reading-print');
    const closeButton = document.getElementById('register-reading-modal-close');
    const doneButton = document.getElementById('register-reading-modal-done');
    const readingUrl = id => '<?= APP_URL ?>/cashier/register-reading.php?id=' + encodeURIComponent(id);

    function closeReadingModal() {
      modal.classList.add('hidden');
      modal.setAttribute('aria-hidden', 'true');
      frame.src = 'about:blank';
    }

    function openReadingModal(id, type = '') {
      const url = readingUrl(id);
      title.innerHTML = '<i class="fa-solid fa-receipt"></i> ' + (type === 'X' ? 'X Reading' : type === 'Z' ? 'Z Reading' : 'Register reading');
      frame.src = url + '&embed=1';
      print.href = url;
      modal.classList.remove('hidden');
      modal.setAttribute('aria-hidden', 'false');
      closeButton.focus();
    }

    document.querySelectorAll('.reading-modal-link').forEach(link => {
      link.addEventListener('click', event => {
        event.preventDefault();
        openReadingModal(link.dataset.readingId, link.dataset.readingType || '');
      });
    });
    closeButton.addEventListener('click', closeReadingModal);
    doneButton.addEventListener('click', closeReadingModal);
    modal.addEventListener('click', event => {
      if (event.target === modal) closeReadingModal();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !modal.classList.contains('hidden')) closeReadingModal();
    });

    const params = new URLSearchParams(window.location.search);
    const pendingId = params.get('reading');
    if (pendingId && /^\d+$/.test(pendingId)) {
      const link = Array.from(document.querySelectorAll('.reading-modal-link')).find(item => item.dataset.readingId === pendingId);
      openReadingModal(pendingId, link ? link.dataset.readingType : '');
      params.delete('reading');
      const query = params.toString();
      history.replaceState({}, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
    }
  })();
</script>