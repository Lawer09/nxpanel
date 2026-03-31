<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\NodeDeployTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeployTemplateController extends Controller
{
    /**
     * 获取模板列表
     *
     * GET /admin/deploy-template/fetch
     * 参数：name(模糊), node_type, is_default, page, page_size
     */
    public function fetch(Request $request)
    {
        $query = NodeDeployTemplate::query();

        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->input('name') . '%');
        }
        if ($request->filled('node_type')) {
            $query->where('node_type', $request->input('node_type'));
        }
        if ($request->filled('is_default')) {
            $query->where('is_default', (bool) $request->input('is_default'));
        }

        $pageSize = $request->integer('page_size', 20);
        $current  = $request->integer('page', 1);
        $result   = $query->orderByDesc('is_default')->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $current);

        return $this->ok([
            'data'     => $result->items(),
            'total'    => $result->total(),
            'pageSize' => $result->perPage(),
            'page'     => $result->currentPage(),
        ]);
    }

    /**
     * 获取模板详情
     *
     * GET /admin/deploy-template/detail?id=1
     */
    public function detail(Request $request)
    {
        $request->validate(['id' => 'required|integer']);

        $template = NodeDeployTemplate::findOrFail($request->integer('id'));
        return $this->ok($template);
    }

    /**
     * 创建模板
     *
     * POST /admin/deploy-template/save
     */
    public function save(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:100',
            'description'      => 'nullable|string|max:500',
            'node_type'        => 'required|string|in:vless,vmess,trojan,shadowsocks,hysteria,hysteria2,tuic,anytls',
            'node_type2'       => 'nullable|string|max:20',
            'core_type'        => 'nullable|integer|in:1,2,3',
            'node_inout_type'  => 'nullable|string|in:stand,in,out',
            'group_ids'        => 'nullable|array',
            'group_ids.*'      => 'string',
            'route_ids'        => 'nullable|array',
            'route_ids.*'      => 'integer',
            'tags'             => 'nullable|array',
            'show'             => 'nullable|boolean',
            'rate'             => 'nullable|string|max:10',
            'tls'              => 'nullable|integer|in:0,1,2',
            'server_name'      => 'nullable|string|max:255',
            'flow'             => 'nullable|string|max:50',
            'network'          => 'nullable|string|max:20',
            'network_settings' => 'nullable|array',
            'cert_mode'        => 'nullable|string|in:none,http,dns,self',
            'cert_domain'      => 'nullable|string|max:255',
            'release_repo'     => 'nullable|string|max:100',
            'script_repo'      => 'nullable|string|max:100',
            'script_branch'    => 'nullable|string|max:50',
            'github_token'     => 'nullable|string|max:200',
            'cipher'           => 'nullable|string|max:50',
            'plugin'           => 'nullable|string|max:50',
            'plugin_opts'      => 'nullable|string|max:500',
            'extra'            => 'nullable|array',
            'is_default'       => 'nullable|boolean',
        ]);

        DB::beginTransaction();
        try {
            // 同一时间只允许一个默认模板
            if (!empty($data['is_default'])) {
                NodeDeployTemplate::where('is_default', true)->update(['is_default' => false]);
            }

            $template = NodeDeployTemplate::create($data);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([500, '创建失败: ' . $e->getMessage()]);
        }

        return $this->ok($template);
    }

    /**
     * 更新模板
     *
     * POST /admin/deploy-template/update
     */
    public function update(Request $request)
    {
        $request->validate(['id' => 'required|integer']);

        $data = $request->validate([
            'name'             => 'sometimes|required|string|max:100',
            'description'      => 'nullable|string|max:500',
            'node_type'        => 'sometimes|required|string|in:vless,vmess,trojan,shadowsocks,hysteria,hysteria2,tuic,anytls',
            'node_type2'       => 'nullable|string|max:20',
            'core_type'        => 'nullable|integer|in:1,2,3',
            'node_inout_type'  => 'nullable|string|in:stand,in,out',
            'group_ids'        => 'nullable|array',
            'group_ids.*'      => 'string',
            'route_ids'        => 'nullable|array',
            'route_ids.*'      => 'integer',
            'tags'             => 'nullable|array',
            'show'             => 'nullable|boolean',
            'rate'             => 'nullable|string|max:10',
            'tls'              => 'nullable|integer|in:0,1,2',
            'server_name'      => 'nullable|string|max:255',
            'flow'             => 'nullable|string|max:50',
            'network'          => 'nullable|string|max:20',
            'network_settings' => 'nullable|array',
            'cert_mode'        => 'nullable|string|in:none,http,dns,self',
            'cert_domain'      => 'nullable|string|max:255',
            'release_repo'     => 'nullable|string|max:100',
            'script_repo'      => 'nullable|string|max:100',
            'script_branch'    => 'nullable|string|max:50',
            'github_token'     => 'nullable|string|max:200',
            'cipher'           => 'nullable|string|max:50',
            'plugin'           => 'nullable|string|max:50',
            'plugin_opts'      => 'nullable|string|max:500',
            'extra'            => 'nullable|array',
            'is_default'       => 'nullable|boolean',
        ]);

        $template = NodeDeployTemplate::findOrFail($request->integer('id'));

        DB::beginTransaction();
        try {
            if (!empty($data['is_default'])) {
                NodeDeployTemplate::where('is_default', true)
                    ->where('id', '!=', $template->id)
                    ->update(['is_default' => false]);
            }

            $template->update($data);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([500, '更新失败: ' . $e->getMessage()]);
        }

        return $this->ok($template->fresh());
    }

    /**
     * 删除模板
     *
     * POST /admin/deploy-template/delete
     */
    public function delete(Request $request)
    {
        $request->validate(['id' => 'required|integer']);

        $template = NodeDeployTemplate::findOrFail($request->integer('id'));
        $template->delete();

        return $this->ok(true);
    }

    /**
     * 设为默认模板
     *
     * POST /admin/deploy-template/setDefault
     */
    public function setDefault(Request $request)
    {
        $request->validate(['id' => 'required|integer']);

        DB::transaction(function () use ($request) {
            NodeDeployTemplate::where('is_default', true)->update(['is_default' => false]);
            NodeDeployTemplate::where('id', $request->integer('id'))->update(['is_default' => true]);
        });

        return $this->ok(true);
    }

    /**
     * 预览模板展开后的 deployConfig（调试用）
     *
     * GET /admin/deploy-template/preview?id=1
     */
    public function preview(Request $request)
    {
        $request->validate(['id' => 'required|integer']);

        $template = NodeDeployTemplate::findOrFail($request->integer('id'));
        return $this->ok($template->toDeployConfig());
    }
}
