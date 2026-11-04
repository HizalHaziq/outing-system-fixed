<?php
// Shared live clock, shown on both staff and student pages as a plain
// time reference. Deliberately does NOT recompute curfew status here
// -- that logic lives once, server-side, in curfew.php. Duplicating
// "is it curfew right now" in client JS would risk it drifting out of
// sync with the server (different clock, different logic if curfew.php
// ever changes) and showing the wrong answer to the person who most
// needs the right one.
//
// Two looks, same widget:
//   header (default)               -> stacked: date / weekday / big bold time
//   $clock_inline = true before    -> one plain line, for "Current time: ..."
//   the require                       text inside page content
$clockInline = !empty($clock_inline);
$clock_inline = false; // reset so a later include on the same page defaults to header
?>
<?php if (empty($GLOBALS['clock_assets_done'])): $GLOBALS['clock_assets_done'] = true; ?>
<style>
    /* "#m_header span" in app.css has ID specificity, so the header look
       is scoped under #m_header to win over its colour rule. */
    .live-clock { font-family: inherit; font-variant-numeric: tabular-nums; }
    .live-clock.clk-stacked { display: inline-flex; flex-direction: column; align-items: flex-end; text-align: right; line-height: 1.15; }
    .live-clock.clk-stacked .clk-date,
    .live-clock.clk-stacked .clk-day { display: block; font-size: 11.5px; font-weight: 500; }
    .live-clock.clk-stacked .clk-time { display: block; font-size: 20px; font-weight: 600; letter-spacing: 0.3px; white-space: nowrap; }
    #m_header .live-clock.clk-stacked .clk-date,
    #m_header .live-clock.clk-stacked .clk-day,
    header.simple-header .live-clock.clk-stacked .clk-date,
    header.simple-header .live-clock.clk-stacked .clk-day { color: var(--header-text, #6f727d); }
    #m_header .live-clock.clk-stacked .clk-time,
    header.simple-header .live-clock.clk-stacked .clk-time { color: var(--brand, #716aca); }
    .live-clock.clk-inline .clk-date,
    .live-clock.clk-inline .clk-day,
    .live-clock.clk-inline .clk-time { display: inline; font-weight: 500; margin-right: 6px; }
    /* Phones: time only, so the header keeps room for the menu/bell/avatar. */
    @media (max-width: 600px) {
        .live-clock.clk-stacked .clk-date,
        .live-clock.clk-stacked .clk-day { display: none; }
        .live-clock.clk-stacked .clk-time { font-size: 16px; }
    }
</style>
<?php endif; ?>
<span class="live-clock <?= $clockInline ? 'clk-inline' : 'clk-stacked' ?>">
    <span class="clk-date"></span>
    <span class="clk-day"></span>
    <span class="clk-time"></span>
</span>
<script>
    (function () {
        var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
                      'August', 'September', 'October', 'November', 'December'];
        function pad(n) { return String(n).padStart(2, '0'); }
        // Every clock on the page (header + any inline "Current time") ticks together.
        function tick() {
            var d = new Date();
            var h = d.getHours();
            var ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            var date = d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
            var time = h + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()) + ' ' + ampm;
            var clocks = document.querySelectorAll('.live-clock');
            for (var i = 0; i < clocks.length; i++) {
                clocks[i].querySelector('.clk-date').textContent = date;
                clocks[i].querySelector('.clk-day').textContent = days[d.getDay()];
                clocks[i].querySelector('.clk-time').textContent = time;
            }
        }
        tick();
        if (!window.__clockTimer) { window.__clockTimer = setInterval(tick, 1000); }
    })();
</script>
