/* ============================================================
   ELEGANCE SALON - appointments booking form (Step 5)
   Refreshes the start-time dropdown from slots.php whenever
   the stylist, date or service changes. Server-side checks in
   form.php still protect every save if this script fails.
   ============================================================ */

(function () {
    var staffSel = document.getElementById('staff_id');
    var dateInp  = document.getElementById('appointment_date');
    var servSel  = document.getElementById('service_id');
    var timeSel  = document.getElementById('start_time');
    var note     = document.getElementById('slot-note');
    var exclude  = document.getElementById('exclude_id');
    var form     = document.getElementById('appt-form');

    if (!staffSel || !dateInp || !servSel || !timeSel || !form) {
        return; // not on the booking form
    }

    var BASE = form.getAttribute('data-base') || '';

    function label12(hhmm) {
        var p = hhmm.split(':');
        var h = parseInt(p[0], 10);
        var am = h < 12 ? 'AM' : 'PM';
        var h12 = h % 12;
        if (h12 === 0) { h12 = 12; }
        return (h12 < 10 ? '0' + h12 : h12) + ':' + p[1] + ' ' + am;
    }

    function selectedDuration() {
        var opt = servSel.options[servSel.selectedIndex];
        return opt && opt.getAttribute('data-duration') ? parseInt(opt.getAttribute('data-duration'), 10) : 0;
    }

    function selectedEnd(startHm) {
        var dur = selectedDuration();
        if (!dur || !startHm) { return ''; }
        var t = new Date(2000, 0, 1, parseInt(startHm.split(':')[0], 10), parseInt(startHm.split(':')[1], 10) + dur);
        var h = t.getHours(), m = t.getMinutes();
        var am = h < 12 ? 'AM' : 'PM';
        var h12 = h % 12;
        if (h12 === 0) { h12 = 12; }
        return (h12 < 10 ? '0' + h12 : h12) + ':' + (m < 10 ? '0' + m : m) + ' ' + am;
    }

    function loadSlots(keepValue) {
        var sid = staffSel.value;
        var dt  = dateInp.value;
        var svc = servSel.value;

        if (!note) { return; }
        if (!sid || !dt || !svc) {
            note.textContent = '';
            return;
        }

        note.innerHTML = '<i class="bi bi-hourglass-split"></i> Checking available times...';

        var url = BASE + 'slots.php?staff_id=' + encodeURIComponent(sid) +
                  '&date=' + encodeURIComponent(dt) +
                  '&service_id=' + encodeURIComponent(svc) +
                  '&exclude=' + encodeURIComponent(exclude ? exclude.value : '0');

        fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var previous = keepValue ? timeSel.value : '';
                timeSel.innerHTML = '';

                if (!data.ok) {
                    var none = document.createElement('option');
                    none.value = '';
                    none.textContent = '-- no times --';
                    timeSel.appendChild(none);
                    note.innerHTML = '<i class="bi bi-exclamation-circle text-danger"></i> ' + data.message;
                    return;
                }

                var firstFree = 0;
                var busyCount = 0;
                data.slots.forEach(function (s) {
                    var opt = document.createElement('option');
                    opt.value = s.t;
                    opt.textContent = s.label + (s.ok ? '' : ' - ' + (s.reason === 'past' ? 'past' : 'booked'));
                    if (!s.ok) {
                        opt.disabled = true;
                        busyCount++;
                    } else if (!firstFree) {
                        firstFree = s.t;
                    }
                    timeSel.appendChild(opt);
                });

                var keepOk = false;
                if (previous) {
                    var prevOpt = timeSel.querySelector('option[value="' + previous + '"]');
                    keepOk = prevOpt && !prevOpt.disabled;
                }
                if (keepOk) {
                    timeSel.value = previous;
                } else if (firstFree) {
                    timeSel.value = firstFree;
                }

                var freeCount = data.slots.length - busyCount;
                var chosen = timeSel.value;
                var msg = '<i class="bi bi-info-circle text-muted"></i> ' + freeCount + ' free / ' +
                          busyCount + ' booked on this day';
                if (chosen) {
                    var end = selectedEnd(chosen);
                    msg += ' &middot; selected <b>' + label12(chosen) + '</b>' +
                           (end ? ' (ends about ' + end + ')' : '');
                } else if (!data.slots.length) {
                    msg = '<i class="bi bi-exclamation-circle text-danger"></i> No bookable times that day.';
                }
                note.innerHTML = msg;
            })
            .catch(function () {
                /* offline / JSON problem: keep the static grid usable */
                note.innerHTML = '<i class="bi bi-wifi-off text-muted"></i> Live availability unavailable - ' +
                                 'the time will still be verified when you save.';
                if (!timeSel.options.length || timeSel.options[0].value === '') {
                    timeSel.innerHTML = '';
                    for (var h = 9; h <= 19; h++) {
                        ['00', '15', '30', '45'].forEach(function (mm) {
                            var t = (h < 10 ? '0' + h : h) + ':' + mm;
                            if (t > '19:45') { return; }
                            var opt = document.createElement('option');
                            opt.value = t;
                            opt.textContent = label12(t);
                            timeSel.appendChild(opt);
                        });
                    }
                }
            });
    }

    staffSel.addEventListener('change', function () { loadSlots(false); });
    dateInp.addEventListener('change', function () { loadSlots(false); });
    servSel.addEventListener('change', function () { loadSlots(true); });

    /* initial fill when editing or pre-filled */
    if (staffSel.value && dateInp.value && servSel.value) {
        loadSlots(true);
    }
})();