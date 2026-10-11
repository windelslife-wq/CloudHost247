<div class="smm-client-area">
    <h2>SMM Order Details</h2>

    <?php if (isset($error) && $error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php else: ?>
    <div class="panel panel-<?php echo htmlspecialchars($status_color, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="panel-heading">
            <h4>Order Status: <?php echo htmlspecialchars(ucfirst($order['status']), ENT_QUOTES, 'UTF-8'); ?></h4>
        </div>
        <div class="panel-body">
            <div class="row">
                <div class="col-sm-6">
                    <table class="table table-bordered">
                        <tr>
                            <th>SMM Order ID</th>
                            <td><?php echo htmlspecialchars($order['smm_order_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                        <tr>
                            <th>SMM Service ID</th>
                            <td><?php echo htmlspecialchars($order['smm_service_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                        <tr>
                            <th>Quantity</th>
                            <td><?php echo htmlspecialchars($order['quantity'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                        <tr>
                            <th>Link</th>
                            <td><?php $smmHref = (string) $order['link']; if (preg_match('#^https?://#i', $smmHref)): ?><a href="<?php echo htmlspecialchars($smmHref, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($order['link'], ENT_QUOTES, 'UTF-8'); ?></a><?php else: ?><?php echo htmlspecialchars($order['link'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-sm-6">
                    <table class="table table-bordered">
                        <tr>
                            <th>Start Count</th>
                            <td><?php echo htmlspecialchars($order['start_count'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                        <tr>
                            <th>Remains</th>
                            <td><?php echo htmlspecialchars($order['remains'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                        <tr>
                            <th>Last Checked</th>
                            <td><?php echo htmlspecialchars($order['last_check'], ENT_QUOTES, 'UTF-8') ?: 'Pending first check'; ?></td>
                        </tr>
                        <tr>
                            <th>Created</th>
                            <td><?php echo htmlspecialchars($order['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><h4>Order Timeline</h4></div>
        <div class="panel-body">
            <ul class="list-group">
                <li class="list-group-item">
                    <strong><?php echo htmlspecialchars($order['created_at'], ENT_QUOTES, 'UTF-8'); ?></strong> - Order placed successfully.
                </li>
                <?php if ($order['status'] !== 'pending'): ?>
                <li class="list-group-item">
                    <strong><?php echo htmlspecialchars($order['updated_at'], ENT_QUOTES, 'UTF-8'); ?></strong> - Status updated to <span class="label label-<?php echo htmlspecialchars($status_color, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($order['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</div>
