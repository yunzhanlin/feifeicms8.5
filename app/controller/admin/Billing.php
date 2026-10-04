<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\facade\Db;
use think\facade\Session;
use think\Response;

final class Billing extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit, private readonly SiteSettings $settings)
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $plainCards = (array) Session::pull('new_card_plaintext', []);
        return view('/admin/billing/index', [
            'orders' => Db::table('ffx_orders')->alias('o')->leftJoin(['ffx_users' => 'u'], 'u.id = o.user_id')->field('o.*,u.username')->order('o.id', 'desc')->limit(100)->select()->toArray(),
            'cards' => Db::table('ffx_cards')->alias('c')->leftJoin(['ffx_users' => 'u'], 'u.id = c.used_by')->field('c.*,u.username')->order('c.id', 'desc')->limit(100)->select()->toArray(),
            'plainCards' => $plainCards, 'csrf' => $this->csrf->get(),
        ]);
    }

    public function updateOrder(int $id): Response
    {
        $this->guardCsrf();
        $status = (string) $this->request->post('status', 'pending');
        if (!in_array($status, ['pending', 'paid', 'confirmed', 'cancelled', 'refunded'], true)) {
            return response('订单状态无效', 422);
        }
        [$order, $data] = Db::transaction(function () use ($id, $status): array {
            // Serialize fulfillment by order, not merely by user. A second
            // request must see the first request's committed metadata.
            $order = Db::table('ffx_orders')->where('id', $id)->lock(true)->find();
            if ($order === null) throw new HttpException(404, '订单不存在');
            $data = ['status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s')];
            if ($status === 'paid' && empty($order['paid_at'])) $data['paid_at'] = gmdate('Y-m-d H:i:s');
            if ($status === 'confirmed' && empty($order['confirmed_at'])) {
                $data['confirmed_at'] = gmdate('Y-m-d H:i:s');
                $metadata = is_string($order['metadata'] ?? null) ? json_decode((string) $order['metadata'], true) : ($order['metadata'] ?? []);
                $metadata = is_array($metadata) ? $metadata : [];
                if (empty($metadata['fulfilled']) && !empty($order['user_id'])) {
                    $user = Db::table('ffx_users')->where('id', (int) $order['user_id'])->lock(true)->find();
                    if ($user !== null) {
                        if (in_array((string) $order['product_type'], ['points', 'recharge'], true)) {
                            $points = max(0, (int) round((float) $order['amount'] * $this->settings->int('admin.pay.user_pay_scale', 1, 1, 100000)));
                            Db::table('ffx_users')->where('id', (int) $user['id'])->update(['points' => (int) $user['points'] + $points, 'updated_at' => gmdate('Y-m-d H:i:s')]);
                            $metadata['fulfilled_points'] = $points;
                        } elseif ((string) $order['product_type'] === 'vip') {
                            $days = max(1, (int) $order['quantity']) * $this->settings->int('admin.pay.user_pay_vip_ext', 30, 1, 3650);
                            $base = !empty($user['expires_at']) && strtotime((string) $user['expires_at']) > time() ? strtotime((string) $user['expires_at']) : time();
                            Db::table('ffx_users')->where('id', (int) $user['id'])->update(['expires_at' => gmdate('Y-m-d H:i:s', $base + ($days * 86400)), 'updated_at' => gmdate('Y-m-d H:i:s')]);
                            $metadata['fulfilled_vip_days'] = $days;
                        }
                    }
                    $metadata['fulfilled'] = true;
                    $metadata['fulfilled_at'] = gmdate('c');
                    $data['metadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            Db::table('ffx_orders')->where('id', $id)->update($data);
            return [$order, $data];
        });
        $this->audit->record('order.update', 'order', $id, $order, $data);
        return redirect('/admin/billing');
    }

    public function createCard(): Response
    {
        $this->guardCsrf();
        $count = max(1, min(100, (int) $this->request->post('count', 1)));
        $faceValue = max(1, (int) $this->request->post('face_value', 100));
        $expiresAt = (($expires = trim((string) $this->request->post('expires_at', ''))) !== '') ? str_replace('T', ' ', $expires) : null;
        $plainCards = [];
        $ids = [];
        Db::transaction(function () use ($count, $faceValue, $expiresAt, &$plainCards, &$ids): void {
            for ($index = 0; $index < $count; $index++) {
                $plain = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
                $ids[] = Db::table('ffx_cards')->insertGetId(['card_hash' => hash('sha256', $plain), 'face_value' => $faceValue, 'status' => 'unused', 'expires_at' => $expiresAt, 'created_at' => gmdate('Y-m-d H:i:s')]);
                $plainCards[] = $plain;
            }
        });
        Session::set('new_card_plaintext', $plainCards);
        $this->audit->record('card.create_batch', 'card', implode(',', $ids), null, ['count' => $count, 'face_value' => $faceValue, 'expires_at' => $expiresAt]);
        return redirect('/admin/billing');
    }

    public function disableCard(int $id): Response
    {
        $this->guardCsrf();
        $card = Db::table('ffx_cards')->where('id', $id)->find();
        if ($card === null) {
            throw new HttpException(404, '卡密不存在');
        }
        Db::table('ffx_cards')->where('id', $id)->update(['status' => 'disabled']);
        $this->audit->record('card.disable', 'card', $id, ['status' => $card['status']], ['status' => 'disabled']);
        return redirect('/admin/billing');
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            throw new HttpException(419, '请求已过期');
        }
    }
}
