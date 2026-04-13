<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\SshKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SshKeyController extends Controller
{
    /**
     * 获取密钥列表
     *
     * GET /admin/ssh-key/fetch
     */
    public function fetch(Request $request)
    {
        try {
            $page = $request->query('page', 1);
            $pageSize = $request->query('pageSize', 10);
            $name = $request->query('name');
            $tags = $request->query('tags');
            $providerId = $request->query('provider_id');

            $query = SshKey::query();

            if ($name) {
                $query->where('name', 'like', "%{$name}%");
            }

            if ($tags) {
                $query->where('tags', 'like', "%{$tags}%");
            }

            if ($providerId) {
                $query->where('provider_id', $providerId);
            }

            $total = $query->count();
            $data = $query->with('provider:id,name')
                ->orderByDesc('created_at')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get()
                ->makeHidden(['secret_key']);

            return $this->ok([
                'data'     => $data,
                'total'    => $total,
                'pageSize' => $pageSize,
                'page'     => $page,
            ]);
        } catch (\Exception $e) {
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 创建密钥
     *
     * POST /admin/ssh-key/save
     */
    public function save(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'            => 'required|string|max:255',
                'tags'            => 'nullable|string|max:255',
                'provider_id'     => 'nullable|integer|exists:v2_providers,id',
                'provider_key_id' => 'nullable|string|max:255',
                'secret_key'      => 'required|string',
                'public_key'      => 'nullable|string',
                'note'            => 'nullable|string',
            ]);

            $sshKey = SshKey::create($validated);

            return $this->ok($sshKey->makeHidden(['secret_key']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, '数据验证失败']);
        } catch (\Exception $e) {
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新密钥
     *
     * POST /admin/ssh-key/update
     */
    public function update(Request $request)
    {
        try {
            $id = $request->input('id');

            if (!$id) {
                return $this->error([422, '密钥ID不能为空']);
            }

            $sshKey = SshKey::findOrFail($id);

            $validated = $request->validate([
                'name'            => 'sometimes|required|string|max:255',
                'tags'            => 'nullable|string|max:255',
                'provider_id'     => 'nullable|integer|exists:v2_providers,id',
                'provider_key_id' => 'nullable|string|max:255',
                'secret_key'      => 'sometimes|required|string',
                'public_key'      => 'nullable|string',
                'note'            => 'nullable|string',
            ]);

            $sshKey->update($validated);

            return $this->ok($sshKey->fresh()->makeHidden(['secret_key']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, '数据验证失败']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error([404, '密钥不存在']);
        } catch (\Exception $e) {
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 删除密钥
     *
     * POST /admin/ssh-key/drop
     */
    public function drop(Request $request)
    {
        try {
            $id = $request->input('id');

            if (!$id) {
                return $this->error([422, '密钥ID不能为空']);
            }

            $sshKey = SshKey::findOrFail($id);
            $sshKey->delete();

            return $this->ok(true);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error([404, '密钥不存在']);
        } catch (\Exception $e) {
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 获取密钥详情
     *
     * POST /admin/ssh-key/detail
     */
    public function detail(Request $request)
    {
        try {
            $id = $request->input('id');

            if (!$id) {
                return $this->error([422, '密钥ID不能为空']);
            }

            $sshKey = SshKey::with('provider:id,name')->findOrFail($id);

            return $this->ok($sshKey->makeHidden(['secret_key']));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error([404, '密钥不存在']);
        } catch (\Exception $e) {
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 批量删除密钥
     *
     * POST /admin/ssh-key/batchDrop
     */
    public function batchDrop(Request $request)
    {
        try {
            $ids = $request->input('ids', []);

            if (empty($ids)) {
                return $this->error([422, '请选择要删除的密钥']);
            }

            SshKey::whereIn('id', $ids)->delete();

            return $this->ok(true);
        } catch (\Exception $e) {
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 批量导入密钥
     *
     * POST /admin/ssh-key/batchImport
     * {
     *   "items": [
     *     {
     *       "name": "xxx",
     *       "tags": "xxx",
     *       "provider_id": 1,
     *       "provider_key_id": "xxx",
     *       "secret_key": "xxx",
     *       "note": "xxx"
     *     }
     *   ]
     * }
     */
    public function batchImport(Request $request)
    {
        $items = $request->input('items', []);

        if (empty($items) || !is_array($items)) {
            return $this->error([422, '导入数据不能为空']);
        }

        $allowedFields = [
            'name',
            'tags',
            'provider_id',
            'provider_key_id',
            'secret_key',
            'public_key',
            'note',
        ];

        $created = [];
        $failed = [];

        DB::beginTransaction();
        try {
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    $failed[] = [
                        'index' => $index,
                        'reason' => '数据格式错误，必须为对象',
                    ];
                    continue;
                }

                $data = array_intersect_key($item, array_flip($allowedFields));

                if (empty($data['name'])) {
                    $failed[] = [
                        'index' => $index,
                        'reason' => '缺少必填字段: name',
                    ];
                    continue;
                }

                if (empty($data['secret_key'])) {
                    $failed[] = [
                        'index' => $index,
                        'reason' => '缺少必填字段: secret_key',
                    ];
                    continue;
                }

                if (isset($data['provider_id']) && !is_numeric($data['provider_id'])) {
                    $failed[] = [
                        'index' => $index,
                        'reason' => 'provider_id 必须为整数',
                    ];
                    continue;
                }

                $newKey = SshKey::create($data);
                $created[] = [
                    'id' => $newKey->id,
                    'name' => $newKey->name,
                ];
            }

            DB::commit();

            return $this->ok([
                'created' => $created,
                'failed' => $failed,
                'summary' => [
                    'total' => count($items),
                    'created_count' => count($created),
                    'failed_count' => count($failed),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([500, '批量导入失败: ' . $e->getMessage()]);
        }
    }
}
