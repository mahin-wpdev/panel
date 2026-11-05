(function () {
    'use strict';
    var state = document.getElementById('reseller-traffic-state');
    if (!state) return;
    var route = '';
    try {
        var query = window.location.search.slice(1).split('&');
        for (var i = 0; i < query.length; i++) {
            var pair = query[i].split('=');
            if (pair[0] === '_route') route = decodeURIComponent(pair.slice(1).join('='));
        }
    } catch (ignored) { return; }
    var match = route.match(/^reseller\/customer-view\/(\d+)$/);
    if (!match) return;
    var box = state.parentNode;
    while (box && !/(^|\s)box(\s|$)/.test(box.className || '')) box = box.parentNode;
    if (!box || document.getElementById('reseller-traffic-graph')) return;
    var label = box.querySelector('.box-header .pull-right');
    if (label) label.textContent = 'Auto refresh: 1 second';
    var graphBox = document.createElement('div');
    graphBox.id = 'reseller-traffic-graph';
    graphBox.className = 'box box-default';
    graphBox.innerHTML = '<div class="box-header"><h3 class="box-title">Live Bandwidth Graph</h3></div>' +
        '<div class="box-body"><div style="text-align:center;font-size:12px;margin-bottom:8px">' +
        '<span style="color:#00a65a">\u25cf Download Mbps</span> &nbsp; <span style="color:#00c0ef">\u25cf Upload Mbps</span></div>' +
        '<div id="reseller-traffic-plot" style="width:100%;min-width:0;height:300px"></div>' +
        '<div id="reseller-graph-state" class="text-muted" style="font-size:12px">Waiting for traffic data\u2026</div></div>';
    box.parentNode.insertBefore(graphBox, box.nextSibling);
    var plot = document.getElementById('reseller-traffic-plot');
    var message = document.getElementById('reseller-graph-state');
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('width', '100%');
    svg.setAttribute('height', '100%');
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Live download and upload bandwidth in Mbps');
    svg.style.display = 'block';
    plot.appendChild(svg);
    var samples = [], timer = null, request = null, stopped = false;
    function el(name, attributes, text) {
        var node = document.createElementNS(ns, name);
        for (var key in attributes) if (Object.prototype.hasOwnProperty.call(attributes, key)) node.setAttribute(key, attributes[key]);
        if (text !== undefined) node.textContent = text;
        svg.appendChild(node);
        return node;
    }
    function draw() {
        var width = Math.max(240, plot.clientWidth || 300), height = width < 600 ? 260 : 320;
        plot.style.height = height + 'px';
        svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
        while (svg.firstChild) svg.removeChild(svg.firstChild);
        var left = 42, right = width - 14, top = 12, bottom = height - 32, maximum = 1;
        for (var i = 0; i < samples.length; i++) maximum = Math.max(maximum, samples[i].down, samples[i].up);
        maximum = Math.ceil(maximum * 1.1);
        for (var tick = 0; tick <= 4; tick++) {
            var y = bottom - (bottom - top) * tick / 4;
            el('line', {x1:left, y1:y, x2:right, y2:y, stroke:'#dfe4ea', 'stroke-width':1});
            el('text', {x:left-6, y:y+4, 'text-anchor':'end', fill:'#637381', 'font-size':11}, (maximum * tick / 4).toFixed(maximum < 5 ? 1 : 0));
        }
        function xAt(index) { return samples.length < 2 ? left : left + (right-left) * index / (samples.length-1); }
        function path(field, color) {
            if (!samples.length) return;
            var points = [];
            for (var index = 0; index < samples.length; index++) points.push(xAt(index) + ',' + (bottom - samples[index][field] / maximum * (bottom-top)));
            el('polyline', {points:points.join(' '), fill:'none', stroke:color, 'stroke-width':2, 'stroke-linejoin':'round'});
            var last = samples.length - 1;
            el('circle', {cx:xAt(last), cy:bottom-samples[last][field]/maximum*(bottom-top), r:3, fill:color});
        }
        path('down', '#00a65a'); path('up', '#00c0ef');
        var labels = width < 600 ? 3 : 6;
        for (var slot = 0; slot < Math.min(labels, samples.length); slot++) {
            var index = samples.length === 1 ? 0 : Math.round(slot * (samples.length-1) / (Math.min(labels,samples.length)-1));
            el('text', {x:xAt(index), y:height-10, 'text-anchor':slot===0?'start':(slot===Math.min(labels,samples.length)-1?'end':'middle'), fill:'#637381', 'font-size':10}, samples[index].time);
        }
    }
    function text(id, value) {
        var node = document.getElementById(id);
        if (node) node.textContent = value === undefined || value === null || value === '' ? '\u2014' : value;
    }
    function update(data) {
        text('rt-download', data.total_download); text('rt-upload', data.total_upload); text('rt-total', data.total_usage);
        text('rt-status', data.connected === true ? 'Online' : (data.connected === false ? 'Offline' : 'Unavailable'));
        text('rt-downspeed', data.download_speed); text('rt-upspeed', data.upload_speed);
        text('rt-session', data.session_total); text('rt-uptime', data.uptime);
        var fields = document.getElementById('reseller-traffic-data');
        if (fields) fields.style.display = 'block';
        var down = Number(data.download_bps), up = Number(data.upload_bps);
        if (isFinite(down) && isFinite(up) && data.download_bps !== null && data.upload_bps !== null &&
            data.download_bps !== undefined && data.upload_bps !== undefined) {
            var now = new Date();
            samples.push({down:Math.max(0,down)/1000000, up:Math.max(0,up)/1000000,
                time:now.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', second:'2-digit'})});
            if (samples.length > 60) samples.shift();
            draw(); message.textContent = 'Live download and upload bandwidth';
        } else message.textContent = 'Live bandwidth data is unavailable.';
        state.textContent = data.accounting_available ? 'Live monitor + accounting total updated.' :
            'Live monitor updated; accounting record unavailable.';
    }
    function schedule(delay) {
        if (!stopped && !document.hidden) timer = window.setTimeout(load, delay);
    }
    function load() {
        timer = null;
        if (stopped || document.hidden || request) return;
        request = new XMLHttpRequest();
        var xhr = request;
        xhr.open('GET', window.location.pathname + '?_route=reseller/traffic/' + match[1], true);
        xhr.timeout = 10000;
        function finish(ok) {
            if (request !== xhr) return;
            request = null;
            if (!ok) {
                state.textContent = 'Traffic monitor is unavailable. Retrying\u2026';
                message.textContent = 'Waiting for live bandwidth data\u2026';
            }
            schedule(ok ? 1000 : 5000);
        }
        xhr.onload = function () {
            var ok = false;
            if (xhr.status >= 200 && xhr.status < 300) {
                try { var data = JSON.parse(xhr.responseText); if (!data.error) {update(data); ok = true;} } catch (ignored) {}
            }
            finish(ok);
        };
        xhr.onerror = xhr.ontimeout = function () { finish(false); };
        xhr.send();
    }
    window.addEventListener('resize', draw);
    document.addEventListener('visibilitychange', function () {
        if (timer) {window.clearTimeout(timer); timer = null;}
        if (!document.hidden) load();
    });
    window.addEventListener('pagehide', function () {
        stopped = true;
        if (timer) window.clearTimeout(timer);
        if (request) {var xhr=request; request=null; xhr.abort();}
    });
    draw(); load();
}());
