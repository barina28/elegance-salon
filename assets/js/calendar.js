/* ============================================================
   ELEGANCE SALON - salon calendar (Step 6)
   FullCalendar week / day / month view fed by feed.php.
   - click or drag an empty slot (admin/receptionist) -> booking
     form pre-filled with stylist, date and 15-min start time
   - click a booking -> appointment details page
   - stylist + hide-cancelled filters refetch the events
   Server-side checks in form.php still protect every save.
   ============================================================ */

(function () {
    var calEl = document.getElementById('salon-calendar');
    if (!calEl || typeof FullCalendar === 'undefined') {
        return; /* not on the calendar page, or CDN blocked */
    }

    var feedUrl = calEl.getAttribute('data-feed');
    var bookUrl = calEl.getAttribute('data-book-url');
    var canBook = calEl.getAttribute('data-can-book') === '1';

    var staffSel = document.getElementById('cal-staff');
    var hideSel  = document.getElementById('cal-hide-cancelled');

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    /* local Date -> 'YYYY-MM-DD' (toISOString would shift the day
       by half a day in timezones like UTC+5:30) */
    function ymd(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    function staffId() {
        return staffSel ? (staffSel.value || '0') : '0';
    }

    function hideCancelled() {
        return hideSel && hideSel.checked ? '1' : '0';
    }

    /* build the booking deep-link for a clicked Date */
    function bookLink(d) {
        var h = d.getHours();
        var m = Math.round(d.getMinutes() / 15) * 15;
        if (m === 60) { m = 0; h += 1; }
        if (h < 7 || h > 20) { h = 9; m = 0; }   /* month view / stray clicks -> 09:00 */
        return bookUrl +
               '?staff_id=' + encodeURIComponent(staffId()) +
               '&date=' + encodeURIComponent(ymd(d)) +
               '&time=' + encodeURIComponent(pad(h) + ':' + pad(m));
    }

    var calendar = new FullCalendar.Calendar(calEl, {
        initialView: 'timeGridWeek',
        firstDay: 1,
        height: 'auto',
        allDaySlot: false,
        nowIndicator: true,
        scrollTime: '09:00:00',
        slotMinTime: '07:00:00',
        slotMaxTime: '21:30:00',
        slotDuration: '00:15:00',
        slotLabelInterval: '01:00:00',
        dayMaxEventRows: true,
        selectable: canBook,
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'timeGridWeek,timeGridDay,dayGridMonth'
        },
        buttonText: { today: 'Today', week: 'Week', day: 'Day', month: 'Month' },

        events: function (info, successCallback, failureCallback) {
            var url = feedUrl +
                      '?start=' + encodeURIComponent(ymd(info.start)) +
                      '&end=' + encodeURIComponent(ymd(info.end)) +
                      '&staff_id=' + encodeURIComponent(staffId()) +
                      '&hide_cancelled=' + hideCancelled();

            fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.json(); })
                .then(function (data) { successCallback(data); })
                .catch(function () { failureCallback(); });
        },

        select: function (info) {          /* drag = precise window */
            if (!canBook) { return; }
            window.location = bookLink(info.start);
        },

        dateClick: function (info) {       /* single click = quick book */
            if (!canBook) { return; }
            window.location = bookLink(info.date);
        },

        eventClick: function (info) {      /* booking -> details page */
            info.jsEvent.preventDefault();
            var url = info.event.extendedProps.view_url;
            if (url) { window.location = url; }
        }
    });

    if (staffSel) {
        staffSel.addEventListener('change', function () { calendar.refetchEvents(); });
    }
    if (hideSel) {
        hideSel.addEventListener('change', function () { calendar.refetchEvents(); });
    }

    calendar.render();
})();