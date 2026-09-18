</section>
</div>
<footer class="main-footer">
    <div class="pull-right" id="version" onclick="location.href = '{Text::url('community')}#latestVersion';"></div>
    PHPNuxBill by <a href="https://github.com/hotspotbilling/phpnuxbill" rel="nofollow noreferrer noopener"
        target="_blank">iBNuX</a>, Theme by <a href="https://adminlte.io/" rel="nofollow noreferrer noopener"
        target="_blank">AdminLTE</a>
</footer>
</div>
<script src="{$app_url}/ui/ui/scripts/jquery.min.js"></script>
<script src="{$app_url}/ui/ui/scripts/bootstrap.min.js"></script>
<script src="{$app_url}/ui/ui/scripts/adminlte.min.js"></script>
<script src="{$app_url}/ui/ui/scripts/plugins/select2.min.js"></script>
<script src="{$app_url}/ui/ui/scripts/pace.min.js"></script>
<script src="{$app_url}/ui/ui/summernote/summernote.min.js"></script>
<script src="{$app_url}/ui/ui/scripts/custom.js?2025.2.5"></script>

<script>
    document.getElementById('openSearch').addEventListener('click', function () {
        document.getElementById('searchOverlay').style.display = 'flex';
    });

    document.getElementById('closeSearch').addEventListener('click', function () {
        document.getElementById('searchOverlay').style.display = 'none';
    });

    document.getElementById('searchTerm').addEventListener('keyup', function () {
        let query = this.value;
        $.ajax({
            url: '{Text::url('search_user')}',
            type: 'GET',
            data: { query: query },
            success: function (data) {
                if (data.trim() !== '') {
                    $('#searchResults').html(data).show();
                } else {
                    $('#searchResults').html('').hide();
                }
            }
        });
    });
</script>

<script>
    const toggleIcon = document.getElementById('toggleIcon');
    const body = document.body;
    const savedMode = localStorage.getItem('mode');
    if (savedMode === 'dark') {
        body.classList.add('dark-mode');
        toggleIcon.textContent = '🌞';
    }

    function setMode(mode) {
        if (mode === 'dark') {
            body.classList.add('dark-mode');
            toggleIcon.textContent = '🌞';
        } else {
            body.classList.remove('dark-mode');
            toggleIcon.textContent = '🌜';
        }
    }

    toggleIcon.addEventListener('click', () => {
        if (body.classList.contains('dark-mode')) {
            setMode('light');
            localStorage.setItem('mode', 'light');
        } else {
            setMode('dark');
            localStorage.setItem('mode', 'dark');
        }
    });
</script>

{if isset($xfooter)}
    {$xfooter}
{/if}
{literal}
    <script>
        var listAttApi;
        var posAttApi = 0;
        $(document).ready(function() {
            $('.select2').select2({theme: "bootstrap"});
            $('.select2tag').select2({theme: "bootstrap", tags: true});
            var listAtts = document.querySelectorAll(`button[type="submit"]`);
            listAtts.forEach(function(el) {
                if (el.addEventListener) { // all browsers except IE before version 9
                    el.addEventListener("click", function() {
                        var txt = $(this).html();
                        $(this).html(
                            `<span class="loading"></span>`
                        );
                        setTimeout(() => {
                            $(this).prop("disabled", true);
                        }, 100);
                        setTimeout(() => {
                            $(this).html(txt);
                            $(this).prop("disabled", false);
                        }, 5000);
                    }, false);
                } else {
                    if (el.attachEvent) { // IE before version 9
                        el.attachEvent("click", function() {
                            var txt = $(this).html();
                            $(this).html(
                                `<span class="loading"></span>`
                            );
                            setTimeout(() => {
                                $(this).prop("disabled", true);
                            }, 100);
                            setTimeout(() => {
                                $(this).html(txt);
                                $(this).prop("disabled", false);
                            }, 5000);
                        });
                    }
                }

            });
            setTimeout(() => {
                listAttApi = document.querySelectorAll(`[api-get-text]`);
                apiGetText();
            }, 500);
        });

        function ask(field, text){
            var txt = field.innerHTML;
            if (confirm(text)) {
                setTimeout(() => {
                    field.innerHTML = field.innerHTML.replace(`<span class="loading"></span>`, txt);
                    field.removeAttribute("disabled");
                }, 5000);
                return true;
            } else {
                setTimeout(() => {
                    field.innerHTML = field.innerHTML.replace(`<span class="loading"></span>`, txt);
                    field.removeAttribute("disabled");
                }, 500);
                return false;
            }
        }

        function apiGetText(){
            var el = listAttApi[posAttApi];
            if(el != undefined){
                $.get(el.getAttribute('api-get-text'), function(data) {
                    el.innerHTML = data;
                    posAttApi++;
                    if(posAttApi < listAttApi.length){
                        apiGetText();
                    }
                });
            }
        }

        function setKolaps() {
            var kolaps = getCookie('kolaps');
            if (kolaps) {
                setCookie('kolaps', false, 30);
            } else {
                setCookie('kolaps', true, 30);
            }
            return true;
        }

        function setCookie(name, value, days) {
            var expires = "";
            if (days) {
                var date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = "; expires=" + date.toUTCString();
            }
            document.cookie = name + "=" + (value || "") + expires + "; path=/";
        }

        function getCookie(name) {
            var nameEQ = name + "=";
            var ca = document.cookie.split(';');
            for (var i = 0; i < ca.length; i++) {
                var c = ca[i];
                while (c.charAt(0) == ' ') c = c.substring(1, c.length);
                if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
            }
            return null;
        }

        $(function() {
            $('[data-toggle="tooltip"]').tooltip()
        })
        $("[data-toggle=popover]").popover();
    </script>
{/literal}

<script>{literal}(function(){var state=document.getElementById('reseller-traffic-state');if(!state)return;var route=(new URLSearchParams(window.location.search)).get('_route')||'';var match=route.match(/^reseller\/customer-view\/(\d+)$/);if(!match)return;var id=match[1],box=state.closest('.box'),label=box&&box.querySelector('.box-header .pull-right');if(label)label.textContent='Auto refresh: 1 second';var graphBox=document.createElement('div');graphBox.className='box box-default';graphBox.innerHTML='<div class="box-header"><h3 class="box-title">Live Bandwidth Graph</h3></div><div class="box-body"><canvas id="reseller-traffic-chart" height="100"></canvas></div>';box.parentNode.insertBefore(graphBox,box.nextSibling);var chart;function byId(x){return document.getElementById(x);}function graph(x){if(!window.Chart)return;if(!chart){chart=new Chart(byId('reseller-traffic-chart').getContext('2d'),{type:'line',data:{labels:[],datasets:[{label:'Download Mbps',borderColor:'#00a65a',fill:false,data:[]},{label:'Upload Mbps',borderColor:'#00c0ef',fill:false,data:[]}]},options:{animation:false,responsive:true}});}var d=chart.data;d.labels.push(new Date().toLocaleTimeString());d.datasets[0].data.push((x.download_bps||0)/1000000);d.datasets[1].data.push((x.upload_bps||0)/1000000);if(d.labels.length>60){d.labels.shift();d.datasets[0].data.shift();d.datasets[1].data.shift();}chart.update();}function text(el,value){byId(el).textContent=value||'—';}function load(){fetch(window.location.pathname+'?_route=reseller/traffic/'+id,{credentials:'same-origin'}).then(function(r){if(!r.ok)throw new Error('request failed');return r.json();}).then(function(x){text('rt-download',x.total_download);text('rt-upload',x.total_upload);text('rt-total',x.total_usage);text('rt-status',x.connected===true?'Online':(x.connected===false?'Offline':'Unavailable'));text('rt-downspeed',x.download_speed);text('rt-upspeed',x.upload_speed);text('rt-session',x.session_total);text('rt-uptime',x.uptime);byId('reseller-traffic-data').style.display='block';graph(x);state.textContent=x.accounting_available?'Live monitor + accounting total updated.':'Live monitor updated; accounting record unavailable.';}).catch(function(){state.textContent='Traffic monitor is unavailable.';});}var source=document.createElement('script');source.src='https://cdn.jsdelivr.net/npm/chart.js';source.onload=function(){load();setInterval(load,1000);};source.onerror=function(){load();setInterval(load,1000);};document.head.appendChild(source);})();{/literal}</script>
</body>

</html>
