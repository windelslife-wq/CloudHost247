<div class="smm-orders">
    <h2>SMM Orders</h2>

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo htmlspecialchars((string) ($flash['type'] === 'success' ? 'success' : 'danger'), ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars((string) ($flash['message']), ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <div class="panel panel-default">
        <div class="panel-heading"><h4>All Orders</h4></div>
        <div class="panel-body">
            <?php if (count($orders) > 0): ?>
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>WHMCS Order</th>
                        <th>WHMCS Service</th>
                        <th>SMM Order ID</th>
                        <th>SMM Service</th>
                        <th>Quantity</th>
                        <th>Link</th>
                        <th>Status</th>
                        <th>Start Count</th>
                        <th>Remains</th>
                        <th>Last Check</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($order->id), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($order->whmcs_order_id), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($order->whmcs_service_id), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($order->smm_order_id), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($order->smm_service_id), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($order->quantity), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) (substr($order->link, 0, 40)), ENT_QUOTES, 'UTF-8'); ?>...</td>
                        <td>
                            <span class="label label-<?php
                                echo ($order->status === 'completed') ? 'success' :
                                     (($order->status === 'pending') ? 'warning' :
                                     (($order->status === 'canceled' || $order->status === 'error') ? 'danger' :
                                     (($order->status === 'partial') ? 'warning' : 'info')));
                            ?>"><?php echo htmlspecialchars((string) (ucfirst($order->status)), ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars((string) ($order->start_count), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($order->remains), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($order->last_check ?: 'Never'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($order->created_at), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if (!empty($order->smm_order_id)): ?>
                            <form method="post" action="<?php echo htmlspecialchars((string) ($modulelink), ENT_QUOTES, 'UTF-8'); ?>
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">&action=refresh_order" style="display:inline;">
                                <input type="hidden" name="smm_order_id" value="<?php echo htmlspecialchars((string) ($order->smm_order_id), ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-xs btn-info" title="Refresh Status">
                                    <i class="fas fa-sync"></i>
                                </button>
                            </form>
                            <?php if ($order->status !== 'canceled' && $order->status !== 'completed'): ?>
                            <form method="post" action="<?php echo htmlspecialchars((string) ($modulelink), ENT_QUOTES, 'UTF-8'); ?>
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">&action=cancel_smm_order" style="display:inline;" onsubmit="return confirm('Cancel this order?');">
                                <input type="hidden" name="smm_order_id" value="<?php echo htmlspecialchars((string) ($order->smm_order_id), ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-xs btn-danger" title="Cancel Order">
                                    <i class="fas fa-times"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p>No orders found.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
