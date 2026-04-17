<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Http\Requests\Admin\TicketClose;
use App\Http\Requests\Admin\TicketFetch;
use App\Http\Requests\Admin\TicketReply;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    private function applyFiltersAndSorts(Request $request, $builder)
    {
        if ($request->has('filter')) {
            collect($request->input('filter'))->each(function ($filter) use ($builder) {
                $key = $filter['id'];
                $value = $filter['value'];
                $builder->where(function ($query) use ($key, $value) {
                    if (is_array($value)) {
                        $query->whereIn($key, $value);
                    } else {
                        $query->where($key, 'like', "%{$value}%");
                    }
                });
            });
        }

        if ($request->has('sort')) {
            collect($request->input('sort'))->each(function ($sort) use ($builder) {
                $key = $sort['id'];
                $value = $sort['desc'] ? 'DESC' : 'ASC';
                $builder->orderBy($key, $value);
            });
        }
    }

    public function fetch(TicketFetch $request)
    {
        if ($request->filled('id')) {
            $ticket = Ticket::with('messages', 'user')->find($request->integer('id'));
            if (!$ticket) {
                return $this->error([400202, '工单不存在']);
            }

            $result = $ticket->toArray();
            $result['user'] = UserController::transformUserData($ticket->user);

            return $this->ok($result);
        }

        $ticketModel = Ticket::with('user')
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->has('reply_status'), function ($query) use ($request) {
                $query->whereIn('reply_status', $request->input('reply_status'));
            })
            ->when($request->has('email'), function ($query) use ($request) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('email', $request->input('email'));
                });
            });

        $this->applyFiltersAndSorts($request, $ticketModel);

        $tickets = $ticketModel
            ->latest('updated_at')
            ->paginate(
                perPage: $request->integer('pageSize', 10),
                page: $request->integer('page', 1)
            );

        $tickets->getCollection()->transform(function ($ticket) {
            $ticketData = $ticket->toArray();
            $ticketData['user'] = UserController::transformUserData($ticket->user);
            return $ticketData;
        });

        return $this->ok([
            'data' => $tickets->items(),
            'total' => $tickets->total(),
            'page' => $tickets->currentPage(),
            'pageSize' => $tickets->perPage(),
        ]);
    }

    public function reply(TicketReply $request)
    {
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $request->input('id'),
            $request->input('message'),
            $request->user()->id
        );
        return $this->ok(true);
    }

    public function close(TicketClose $request)
    {
        try {
            $ticket = Ticket::findOrFail($request->input('id'));
            $ticket->status = Ticket::STATUS_CLOSED;
            $ticket->save();
            return $this->ok(true);
        } catch (ModelNotFoundException $e) {
            return $this->error([400202, '工单不存在']);
        } catch (\Exception $e) {
            return $this->error([500101, '关闭失败']);
        }
    }

    public function show($ticketId)
    {
        $ticket = Ticket::with([
            'user',
            'messages' => function ($query) {
                $query->with(['user']); // 如果需要用户信息
            }
        ])->findOrFail($ticketId);

        // 自动包含 is_me 属性
        return $this->ok([
            'data' => $ticket
        ]);
    }
}
