<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TemplateController extends Controller
{
    // 模板公共字段验证规则（与 ServerSave 保持一致，但全部可选）
    private const UTLS_RULES = [
        'protocol_settings.utls.enabled'     => 'nullable|boolean',
        'protocol_settings.utls.fingerprint' => 'nullable|string',
    ];

    private const MULTIPLEX_RULES = [
        'protocol_settings.multiplex.enabled'         => 'nullable|boolean',
        'protocol_settings.multiplex.protocol'        => 'nullable|string',
        'protocol_settings.multiplex.max_connections' => 'nullable|integer',
        'protocol_settings.multiplex.min_streams'     => 'nullable|integer',
        'protocol_settings.multiplex.max_streams'     => 'nullable|integer',
        'protocol_settings.multiplex.padding'         => 'nullable|boolean',
        'protocol_settings.multiplex.brutal.enabled'  => 'nullable|boolean',
        'protocol_settings.multiplex.brutal.up_mbps'  => 'nullable|integer',
        'protocol_settings.multiplex.brutal.down_mbps'=> 'nullable|integer',
    ];

    // 各协议专属的 protocol_settings 子字段规则（全部 nullable，模板允许部分填写）
    private const PROTOCOL_RULES = [
        'shadowsocks' => [
            'protocol_settings.cipher'              => 'nullable|string',
            'protocol_settings.obfs'                => 'nullable|string',
            'protocol_settings.obfs_settings.path'  => 'nullable|string',
            'protocol_settings.obfs_settings.host'  => 'nullable|string',
            'protocol_settings.plugin'              => 'nullable|string',
            'protocol_settings.plugin_opts'         => 'nullable|string',
        ],
        'vmess' => [
            'protocol_settings.tls'                          => 'nullable|integer',
            'protocol_settings.network'                      => 'nullable|string',
            'protocol_settings.network_settings'             => 'nullable|array',
            'protocol_settings.tls_settings.server_name'    => 'nullable|string',
            'protocol_settings.tls_settings.allow_insecure' => 'nullable|boolean',
        ],
        'trojan' => [
            'protocol_settings.network'          => 'nullable|string',
            'protocol_settings.network_settings' => 'nullable|array',
            'protocol_settings.server_name'      => 'nullable|string',
            'protocol_settings.allow_insecure'   => 'nullable|boolean',
        ],
        'hysteria' => [
            'protocol_settings.version'               => 'nullable|integer',
            'protocol_settings.alpn'                  => 'nullable|string',
            'protocol_settings.obfs.open'             => 'nullable|boolean',
            'protocol_settings.obfs.type'             => 'nullable|string',
            'protocol_settings.obfs.password'         => 'nullable|string',
            'protocol_settings.tls.server_name'       => 'nullable|string',
            'protocol_settings.tls.allow_insecure'    => 'nullable|boolean',
            'protocol_settings.bandwidth.up'          => 'nullable|integer',
            'protocol_settings.bandwidth.down'        => 'nullable|integer',
            'protocol_settings.hop_interval'          => 'nullable|integer',
        ],
        'vless' => [
            'protocol_settings.tls'                                  => 'nullable|integer',
            'protocol_settings.network'                              => 'nullable|string',
            'protocol_settings.network_settings'                     => 'nullable|array',
            'protocol_settings.flow'                                 => 'nullable|string',
            'protocol_settings.tls_settings.server_name'            => 'nullable|string',
            'protocol_settings.tls_settings.allow_insecure'         => 'nullable|boolean',
            'protocol_settings.reality_settings.allow_insecure'     => 'nullable|boolean',
            'protocol_settings.reality_settings.server_name'        => 'nullable|string',
            'protocol_settings.reality_settings.server_port'        => 'nullable|integer',
            'protocol_settings.reality_settings.public_key'         => 'nullable|string',
            'protocol_settings.reality_settings.private_key'        => 'nullable|string',
            'protocol_settings.reality_settings.short_id'           => 'nullable|string',
        ],
        'tuic' => [
            'protocol_settings.version'              => 'nullable|integer',
            'protocol_settings.congestion_control'   => 'nullable|string',
            'protocol_settings.alpn'                 => 'nullable|array',
            'protocol_settings.udp_relay_mode'       => 'nullable|string',
            'protocol_settings.tls.server_name'      => 'nullable|string',
            'protocol_settings.tls.allow_insecure'   => 'nullable|boolean',
        ],
        'anytls' => [
            'protocol_settings.tls.server_name'    => 'nullable|string',
            'protocol_settings.tls.allow_insecure' => 'nullable|boolean',
            'protocol_settings.padding_scheme'      => 'nullable|array',
        ],
        'socks' => [
            'protocol_settings.tls'                          => 'nullable|integer',
            'protocol_settings.tls_settings.allow_insecure' => 'nullable|boolean',
        ],
        'naive' => [
            'protocol_settings.tls'          => 'nullable|integer',
            'protocol_settings.tls_settings' => 'nullable|array',
        ],
        'http' => [
            'protocol_settings.tls'                          => 'nullable|integer',
            'protocol_settings.tls_settings.server_name'    => 'nullable|string',
            'protocol_settings.tls_settings.allow_insecure' => 'nullable|boolean',
        ],
        'mieru' => [
            'protocol_settings.transport'       => 'nullable|string|in:TCP,UDP',
            'protocol_settings.traffic_pattern' => 'nullable|string',
        ],
    ];

    private function buildRules(string $type): array
    {
        $base = [
            'name'              => 'required|string|max:100',
            'description'       => 'nullable|string|max:500',
            'is_default'        => 'nullable|boolean',
            'type'              => 'required|in:' . implode(',', Server::VALID_TYPES),
            'spectific_key'     => 'nullable|string',
            'code'              => 'nullable|string',
            'show'              => 'nullable|boolean',
            'host'              => 'nullable|string',
            'port'              => 'nullable',
            'server_port'       => 'nullable',
            'rate'              => 'nullable|numeric',
            'rate_time_enable'  => 'nullable|boolean',
            'rate_time_ranges'  => 'nullable|array',
            'rate_time_ranges.*.start' => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.end'   => 'required_with:rate_time_ranges|string|date_format:H:i',
            'rate_time_ranges.*.rate'  => 'required_with:rate_time_ranges|numeric|min:0',
            'group_ids'         => 'nullable|array',
            'route_ids'         => 'nullable|array',
            'parent_id'         => 'nullable|integer',
            'tags'              => 'nullable|array',
            'excludes'          => 'nullable|array',
            'ips'               => 'nullable|array',
            'protocol_settings' => 'nullable|array',
            'custom_outbounds'  => 'nullable|array',
            'custom_routes'     => 'nullable|array',
            'cert_config'       => 'nullable|array',
        ];

        // 合并协议专属规则
        $protocolRules = self::PROTOCOL_RULES[$type] ?? [];
        if (in_array($type, ['vmess', 'vless', 'trojan', 'mieru'])) {
            $protocolRules = array_merge($protocolRules, self::MULTIPLEX_RULES, self::UTLS_RULES);
        }

        return array_merge($base, $protocolRules);
    }

    /**
     * 获取模板列表
     *
     * GET /admin/server/template/fetch
     * 参数: name(模糊), type, is_default, page, page_size
     */
    public function fetch(Request $request)
    {
        $query = ServerTemplate::query();

        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->input('name') . '%');
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('is_default')) {
            $query->where('is_default', (bool) $request->input('is_default'));
        }

        $pageSize = $request->integer('page_size', 20);
        $current  = $request->integer('page', 1);
        $total    = $query->count();
        $items    = $query->orderByDesc('is_default')
            ->orderByDesc('id')
            ->offset(($current - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return $this->ok([
            'data'     => $items,
            'total'    => $total,
            'pageSize' => $pageSize,
            'page'     => $current,
        ]);
    }

    /**
     * 获取模板详情
     *
     * GET /admin/server/template/detail?id=1
     */
    public function detail(Request $request)
    {
        $request->validate(['id' => 'required|integer']);

        $template = ServerTemplate::findOrFail($request->integer('id'));
        return $this->ok($template);
    }

    /**
     * 创建模板
     *
     * POST /admin/server/template/save
     */
    public function save(Request $request)
    {
        $request->validate(['type' => 'required|in:' . implode(',', Server::VALID_TYPES)]);
        $data = $request->validate($this->buildRules($request->input('type')));

        DB::beginTransaction();
        try {
            if (!empty($data['is_default'])) {
                ServerTemplate::where('is_default', true)->update(['is_default' => false]);
            }
            $template = ServerTemplate::create($data);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ServerTemplate save failed', ['error' => $e->getMessage()]);
            return $this->error([500, '创建失败: ' . $e->getMessage()]);
        }

        return $this->ok($template);
    }

    /**
     * 更新模板
     *
     * POST /admin/server/template/update
     */
    public function update(Request $request)
    {
        $request->validate(['id' => 'required|integer', 'type' => 'required|in:' . implode(',', Server::VALID_TYPES)]);
        $data = $request->validate($this->buildRules($request->input('type')));

        $template = ServerTemplate::findOrFail($request->integer('id'));

        DB::beginTransaction();
        try {
            if (!empty($data['is_default'])) {
                ServerTemplate::where('is_default', true)
                    ->where('id', '!=', $template->id)
                    ->update(['is_default' => false]);
            }
            $template->update($data);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ServerTemplate update failed', ['error' => $e->getMessage()]);
            return $this->error([500, '更新失败: ' . $e->getMessage()]);
        }

        return $this->ok($template->fresh());
    }

    /**
     * 删除模板
     *
     * POST /admin/server/template/delete
     */
    public function delete(Request $request)
    {
        $request->validate(['id' => 'required|integer']);

        $template = ServerTemplate::findOrFail($request->integer('id'));
        $template->delete();

        return $this->ok(true);
    }

    /**
     * 设为默认模板
     *
     * POST /admin/server/template/setDefault
     */
    public function setDefault(Request $request)
    {
        $request->validate(['id' => 'required|integer']);

        DB::transaction(function () use ($request) {
            ServerTemplate::where('is_default', true)->update(['is_default' => false]);
            ServerTemplate::where('id', $request->integer('id'))->update(['is_default' => true]);
        });

        return $this->ok(true);
    }

    /**
     * 从现有节点保存为模板
     *
     * POST /admin/server/template/saveFromNode
     */
    public function saveFromNode(Request $request)
    {
        $request->validate([
            'server_id' => 'required|integer',
            'name'      => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $server = \App\Models\Server::findOrFail($request->integer('server_id'));

        // 排除节点专有字段，只保留配置字段
        $excludeFields = ['id', 'last_check_at', 'last_push_at', 'sort', 'online_count', 'created_at', 'updated_at'];
        $config = collect($server->toArray())->except($excludeFields)->all();

        DB::beginTransaction();
        try {
            $template = ServerTemplate::create(array_merge($config, [
                'name'        => $request->input('name'),
                'description' => $request->input('description'),
                'is_default'  => false,
            ]));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([500, '保存失败: ' . $e->getMessage()]);
        }

        return $this->ok($template);
    }

    /**
     * 预览模板展开为节点配置（去除模板专有字段）
     *
     * GET /admin/server/template/preview?id=1
     */
    public function preview(Request $request)
    {
        $request->validate(['id' => 'required|integer']);

        $template = ServerTemplate::findOrFail($request->integer('id'));
        return $this->ok($template->toServerConfig());
    }
}
