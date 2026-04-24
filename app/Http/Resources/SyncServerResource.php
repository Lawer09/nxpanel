<?php

namespace App\Http\Resources;

use App\Models\SyncServer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SyncServer
 */
class SyncServerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'serverId'        => $this->server_id,
            'serverName'      => $this->server_name,
            'hostIp'          => $this->host_ip,
            'port'            => $this->port,
            'tags'            => $this->tags,
            'capabilities'    => $this->capabilities,
            'status'          => $this->status,
            'lastHeartbeatAt' => $this->last_heartbeat_at,
            'createdAt'       => $this->created_at,
            'updatedAt'       => $this->updated_at,
        ];
    }
}
