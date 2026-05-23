/**
 * Vanilla JS for the prompt-test page.
 * No bundler, no framework — keeps the plugin drop-in installable.
 */
(() => {
  const cfg = window.OxaAdmin || {};
  const $prompt   = document.getElementById('oxa-prompt');
  const $provider = document.getElementById('oxa-prompt-provider');
  const $genBtn   = document.getElementById('oxa-btn-generate');
  const $newBtn   = document.getElementById('oxa-btn-create');
  const $status   = document.getElementById('oxa-prompt-status');
  const $output   = document.getElementById('oxa-prompt-output');

  if (!$prompt || !cfg.restRoot) return;

  const setStatus = (msg) => { if ($status) $status.textContent = msg; };
  const setOutput = (data) => {
    if (!$output) return;
    $output.textContent = typeof data === 'string'
      ? data
      : JSON.stringify(data, null, 2);
  };

  const post = async (endpoint) => {
    const prompt = ($prompt.value || '').trim();
    if (!prompt) { setStatus('Prompt is empty.'); return; }
    const provider = $provider ? $provider.value : '';

    setStatus('Generating…');
    setOutput('');

    [$genBtn, $newBtn].forEach((b) => b && (b.disabled = true));

    try {
      const res = await fetch(cfg.restRoot + endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce || '',
        },
        body: JSON.stringify({ prompt, provider }),
      });
      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        setStatus(`Error (${res.status}): ${data.error || 'unknown error'}`);
        setOutput(data);
        return;
      }
      setStatus(`OK (${res.status}).`);
      setOutput(data);
    } catch (err) {
      setStatus('Network error.');
      setOutput(String(err));
    } finally {
      [$genBtn, $newBtn].forEach((b) => b && (b.disabled = false));
    }
  };

  if ($genBtn) $genBtn.addEventListener('click', () => post('generate'));
  if ($newBtn) $newBtn.addEventListener('click', () => post('pages'));
})();
