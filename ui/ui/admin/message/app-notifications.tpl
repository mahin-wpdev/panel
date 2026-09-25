<div class="row">
    <div class="col-md-12">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">
                <i class="fa fa-bell"></i> App Push Notifications
            </div>
            <div class="panel-body">
                <div class="row" style="margin-bottom:15px">
                    <div class="col-sm-4">
                        <div class="well text-center">
                            <div style="font-size:26px"><i class="fa fa-mobile"></i></div>
                            <strong>{$push_device_count}</strong><br>
                            <small>Registered devices</small>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="well text-center">
                            <div style="font-size:26px"><i class="fa fa-users"></i></div>
                            <strong>{$push_customer_count}</strong><br>
                            <small>Customers with app push</small>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="well text-center">
                            <div style="font-size:26px"><i class="fa fa-shield"></i></div>
                            <strong>{$push_staff_count}</strong><br>
                            <small>Admin devices</small>
                        </div>
                    </div>
                </div>

                {if !$push_ready}
                    <div class="alert alert-danger">
                        <i class="fa fa-exclamation-triangle"></i>
                        Mobile push token storage is not configured yet.
                    </div>
                {/if}

                <form class="form-horizontal" method="post"
                    action="{Text::url('appnotifications/send-post')}">
                    <input type="hidden" name="csrf_token" value="{$csrf_token}">

                    <div class="form-group">
                        <label class="col-md-2 control-label">
                            <i class="fa fa-crosshairs"></i> Target
                        </label>
                        <div class="col-md-6">
                            <select class="form-control" name="target" id="pushTarget">
                                <option value="all_customers">All customers with the app</option>
                                <option value="all_active">Active customers with the app</option>
                                <option value="customer">One customer</option>
                                <option value="staff">Admin devices (test)</option>
                            </select>
                            <p class="help-block">
                                Only devices that have opened and signed in to Arivo ISP Billing can receive push.
                            </p>
                        </div>
                    </div>

                    <div class="form-group" id="customerTargetWrap" style="display:none">
                        <label class="col-md-2 control-label">
                            <i class="fa fa-user"></i> Customer
                        </label>
                        <div class="col-md-6">
                            <select id="pushCustomer" class="form-control select2"
                                name="id_customer" style="width:100%"
                                data-placeholder="Search customer by name, username or phone"></select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">
                            <i class="fa fa-header"></i> Title
                        </label>
                        <div class="col-md-6">
                            <input class="form-control" type="text" name="title"
                                id="pushTitle" maxlength="120"
                                value="Arivo ISP Billing"
                                placeholder="Notification title" required>
                            <small class="text-muted"><span id="titleCount">17</span>/120</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">
                            <i class="fa fa-comment"></i> Message
                        </label>
                        <div class="col-md-6">
                            <textarea class="form-control" name="message" id="pushMessage"
                                rows="5" maxlength="1000"
                                placeholder="Write the notification message..." required></textarea>
                            <small class="text-muted"><span id="messageCount">0</span>/1000</small>
                        </div>
                        <div class="col-md-4">
                            <p class="help-block">
                                <i class="fa fa-info-circle"></i>
                                The notification is delivered through Firebase Cloud Messaging.
                                When the app is in the background or closed, Android shows it as a system notification.
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-md-offset-2 col-md-10">
                            <button type="submit" class="btn btn-success"
                                {if !$push_ready}disabled{/if}
                                onclick="return ask(this, 'Send this push notification now?')">
                                <i class="fa fa-paper-plane"></i> Send App Notification
                            </button>
                            <a href="{Text::url('message/send')}" class="btn btn-default">
                                Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var title = document.getElementById('pushTitle');
    var message = document.getElementById('pushMessage');
    var titleCount = document.getElementById('titleCount');
    var messageCount = document.getElementById('messageCount');
    function updateCounts() {
        titleCount.textContent = title.value.length;
        messageCount.textContent = message.value.length;
    }
    title.addEventListener('input', updateCounts);
    message.addEventListener('input', updateCounts);
    updateCounts();
});
</script>

{include file="sections/footer.tpl"}
