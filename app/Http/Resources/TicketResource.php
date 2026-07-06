<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {  
        $data = [
            "id" => $this['id'],
            "level" => $this['level'],
            "reply_status" => $this['reply_status'],
            "status" => $this['status'],
            "subject" => $this['subject'],
            "personal_email" => $this['personal_email'],
            "message" => array_key_exists('message',$this->additional) ? MessageResource::collection($this['message']) : null,
            "latest_message" => $this->relationLoaded('latestMessage') && $this->latestMessage
                ? MessageResource::make($this->latestMessage)
                : null,
            "created_at" => $this['created_at'],
            "updated_at" => $this['updated_at']
        ];
        if(!config('hidden_features.enable_exposed_user_count_fix')) $data['user_id']= $this['user_id'];
        return $data;

    }
}
