<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \DateTimeImmutable $at */ ?>
<?php /* Listens on demo, and on sse:demo fetches itself again and takes its
   own place: the same re-fetch a live table does, in miniature. The stream
   client in app.js turns the hub's events into that sse:demo. */ ?>
<section id="stream-demo" class="stream-demo"
         data-stream="demo"
         hx-nonce="<?= $this->e($this->cspNonce()) ?>"
         hx-get="/stream/demo"
         hx-trigger="sse:demo"
         hx-swap="outerHTML">
    <p class="mb-1"><strong>Live updates</strong> · refreshed at <time datetime="<?= $this->e($at->format(DATE_ATOM)) ?>"><?= $this->e($at->format('H:i:s')) ?></time></p>
    <p class="form-text mb-0">Run <code>./hydra broadcast:demo</code> and this box refreshes in every tab that has it open.</p>
</section>
