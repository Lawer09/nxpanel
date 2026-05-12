<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'projectCode' => $this->project_code,
            'projectName' => $this->project_name,
            'ownerName'   => $this->owner_name,
            'department'  => $this->department,
            'status'      => $this->status,
            'remark'      => $this->remark,
            'createdAt'   => $this->created_at,
            'updatedAt'   => $this->updated_at,
        ];
    }
}
