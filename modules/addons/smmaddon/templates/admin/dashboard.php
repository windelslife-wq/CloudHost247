<div class="smm-dashboard">
    <h2>SMM Panel Dashboard</h2>

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo htmlspecialchars((string) ($flash['type'] === 'success' ? 'success' : 'danger'), ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars((string) ($flash['message']), ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <div class="row" style="margin-top:20px;">
        <div class="col-sm-2">
            <div class="panel panel-primary">
                <div class="panel-heading">Total Services</div>
                <div class="panel-body text-center">
                    <h3><?php echo htmlspecialchars((string) ($stats['total_services']), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-sm-2">
            <div class="panel panel-success">
                <div class="panel-heading">Active Services</div>
                <div class="panel-body text-center">
                    <h3><?php echo htmlspecialchars((string) ($stats['active_services']), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-sm-2">
            <div class="panel panel-info">
                <div class="panel-heading">Total Orders</div>
                <div class="panel-body text-center">
                    <h3><?php echo htmlspecialchars((string) ($stats['total_orders']), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-sm-2">
            <div class="panel panel-warning">
                <div class="panel-heading">Pending</div>
                <div class="panel-body text-center">
                    <h3><?php echo htmlspecialchars((string) ($stats['pending_orders']), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-sm-2">
            <div class="panel panel-default">
                <div class="panel-heading">Processing</div>
                <div class="panel-body text-center">
                    <h3><?php echo htmlspecialchars((string) ($stats['processing_orders']), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-sm-2">
            <div class="panel panel-success">
                <div class="panel-heading">Completed</div>
                <div class="panel-body text-center">
                    <h3><?php echo htmlspecialchars((string) ($stats['completed_orders']), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="panel panel-default" style="margin-top:20px;">
        <div class="panel-heading">
            <h4>Recent Orders</h4>
        </div>
        <div class="panel-body">
            <?php if (count($recentOrders) > 0): ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>SMM Order</th>
                        <th>Service ID</th>
                        <th>Quantity</th>
                        <th>Link</th>
                        <th>Status</th>
                        <th>Last Check</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentOrders as $order): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($order->id), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($order->smm_order_id), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($order->smm_service_id), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($order->quantity), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) (substr($order->link, 0, 50)), ENT_QUOTES, 'UTF-8'); ?>...</td>
                        <td>
                            <span class="label label-<?php
                                echo ($order->status === 'completed') ? 'success' :
                                     (($order->status === 'pending') ? 'warning' :
                                     (($order->status === 'canceled' || $order->status === 'error') ? 'danger' : 'info'));
                            ?>"><?php echo htmlspecialchars((string) (ucfirst($order->status)), ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars((string) ($order->last_check ?: 'Never'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($order->created_at), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p>No orders found yet.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
