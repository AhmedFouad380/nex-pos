<?php

namespace App\Http\Controllers\Api;

use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Actions\RecordJournalEntry;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Foundation\Models\Location;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Wallet\Actions\ConfirmWalletTransaction;
use App\Domain\Wallet\Actions\IngestWalletMessage;
use App\Domain\Wallet\Actions\RejectWalletTransaction;
use App\Domain\Wallet\Enums\WalletTarget;
use App\Domain\Wallet\Enums\WalletTransactionStatus as Status;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDevice;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\DevicePairing;
use App\Domain\Wallet\Services\MessageParser;
use App\Domain\Wallet\Services\WalletSuggestions;
use App\Models\User;
use App\Support\DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Support\CauserResolver;

/** The API the wallet app talks to. Every route but pair() runs as the paired device (Sanctum token). */
class WalletDeviceController
{
    public function pair(Request $request, DevicePairing $pairing): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'device_name' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:20'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);
        ['device' => $device, 'token' => $token] = $this->domain(fn () => $pairing->redeem($data['code'], $data['device_name'], $data['platform'] ?? null, $data['app_version'] ?? null));

        return response()->json(['token' => $token, ...$this->config($device)], 201);
    }

    public function showConfig(Request $request): JsonResponse
    {
        return response()->json($this->config($this->device($request)));
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $device->update(['last_seen_at' => now(), 'app_version' => $request->input('app_version', $device->app_version)]);

        return response()->json(['ok' => true, 'pending' => $this->scope($device)->where('status', Status::Pending)->count()]);
    }

    /** One or more SMS (several when the phone was offline). Idempotent per message. */
    public function messages(Request $request, IngestWalletMessage $ingest): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:100'],
            'messages.*.body' => ['required', 'string', 'max:2000'],
            'messages.*.sender' => ['nullable', 'string', 'max:60'],
            'messages.*.received_at' => ['nullable', 'date'],
            'messages.*.sim_slot' => ['nullable', 'integer', 'min:0', 'max:9'],
        ]);

        $results = [];
        foreach ($data['messages'] as $message) {
            [$transaction, $new] = $ingest->handle($device, $message['body'], $message['sender'] ?? null,
                isset($message['received_at']) ? Carbon::parse($message['received_at']) : null, $message['sim_slot'] ?? null);
            $results[] = ['id' => $transaction->id, 'new' => $new, 'status' => $transaction->status->value];
        }

        return response()->json(['results' => $results]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $status = $request->query('status', Status::Pending->value);
        $transactions = $this->scope($device)
            ->when($status !== 'all', fn (Builder $q) => $q->where('status', Status::tryFrom((string) $status) ?? Status::Pending))
            ->with(['wallet', 'payment', 'confirmer'])->latest('id')->limit(100)->get();

        return response()->json(['data' => $transactions->map(fn (WalletTransaction $t) => $this->present($t))->all()]);
    }

    public function show(Request $request, WalletTransaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        return response()->json(['data' => $this->present($transaction->load(['wallet', 'payment', 'confirmer']), withSuggestions: true)]);
    }

    /** Search what a transaction can be confirmed against. */
    public function targets(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validate(['type' => ['required', Rule::enum(WalletTarget::class)], 'q' => ['nullable', 'string', 'max:100']]);
        $q = trim((string) ($data['q'] ?? ''));
        $like = '%'.$q.'%';

        $results = match (WalletTarget::from($data['type'])) {
            WalletTarget::Contact => Contact::query()->where('is_active', true)
                ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w->where('name', 'like', $like)
                    ->orWhere('mobile', 'like', $like)->orWhere('code', 'like', $like)))
                ->orderBy('name')->limit(30)->get()
                ->map(fn (Contact $c) => ['id' => $c->id, 'label' => $c->name, 'detail' => $c->type->getLabel().($c->mobile ? ' · '.$c->mobile : '')]),
            WalletTarget::Sale => Sale::query()->where('status', SaleStatus::Final)->whereNull('voided_at')
                ->where('location_id', $device->location_id)->where('due_total', '>', 0)
                ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w->where('invoice_no', 'like', $like)
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $like))))
                ->with('customer')->latest('sale_date')->limit(30)->get()
                ->map(fn (Sale $s) => ['id' => $s->id, 'label' => $s->invoice_no.' — '.$s->customer?->name,
                    'detail' => __('wallet.api.due', ['amount' => number_format((float) $s->due_total, 2)])]),
            WalletTarget::Expense => ExpenseCategory::query()->when($q !== '', fn (Builder $b) => $b->where('name', 'like', $like))
                ->orderBy('name')->limit(50)->get()->map(fn (ExpenseCategory $c) => ['id' => $c->id, 'label' => $c->name, 'detail' => null]),
            WalletTarget::Account => Account::query()->where('is_postable', true)->where('is_active', true)
                ->where(fn (Builder $b) => $b->whereNull('sub_type')->orWhereNotIn('sub_type', RecordJournalEntry::CONTROL_ACCOUNTS))
                ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)))
                ->orderBy('code')->limit(50)->get()->map(fn (Account $a) => ['id' => $a->id, 'label' => $a->code.' — '.$a->name, 'detail' => null]),
            WalletTarget::Service => collect(), // the cash goes through the cashier's shift: nothing to pick
        };

        return response()->json(['data' => $results->values()->all()]);
    }

    public function confirm(Request $request, WalletTransaction $transaction, ConfirmWalletTransaction $confirm): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);
        $data = $request->validate([
            'user_id' => ['required', 'integer'], 'pin' => ['required', 'string', 'max:20'],
            'target_type' => ['required', Rule::enum(WalletTarget::class)], 'target_id' => ['required', 'integer'],
            'wallet_id' => ['nullable', 'integer', 'exists:wallets,id'], 'note' => ['nullable', 'string', 'max:255'],
        ]);
        $user = $this->userByPin($request, (int) $data['user_id'], $data['pin']);
        $this->domain(fn () => $confirm->handle($transaction, WalletTarget::from($data['target_type']), (int) $data['target_id'], $user,
            isset($data['wallet_id']) ? (int) $data['wallet_id'] : null, $data['note'] ?? null));

        return response()->json(['data' => $this->present($transaction->refresh()->load(['wallet', 'payment', 'confirmer']))]);
    }

    public function reject(Request $request, WalletTransaction $transaction, RejectWalletTransaction $reject): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'pin' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:255']]);
        $user = $this->userByPin($request, (int) $data['user_id'], $data['pin']);
        $this->domain(fn () => $reject->handle($transaction, $user, $data['reason']));

        return response()->json(['data' => $this->present($transaction->refresh()->load(['wallet', 'payment', 'confirmer']))]);
    }

    /** Move the phone to another branch — needs someone who manages wallets. */
    public function moveDevice(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validate(['location_id' => ['required', 'integer', 'exists:locations,id'],
            'user_id' => ['required', 'integer'], 'pin' => ['required', 'string', 'max:20']]);
        $user = $this->userByPin($request, (int) $data['user_id'], $data['pin']);
        abort_unless($user->can('wallet.manage') && $user->accessibleLocations()->whereKey($data['location_id'])->exists(), 403, __('wallet.errors.not_allowed'));
        $device->update(['location_id' => (int) $data['location_id']]);

        return response()->json($this->config($device->refresh()));
    }

    /** @return array<string, mixed> */
    private function config(WalletDevice $device): array
    {
        $device->loadMissing('location');

        return [
            'device' => ['id' => $device->id, 'name' => $device->name, 'location' => ['id' => $device->location_id, 'name' => $device->location->name]],
            'senders' => MessageParser::senders(),
            'locations' => Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray(),
            'wallets' => Wallet::query()->where('is_active', true)->where('location_id', $device->location_id)->orderBy('name')->get()
                ->map(fn (Wallet $w) => ['id' => $w->id, 'name' => $w->name, 'provider' => $w->provider->value, 'phone' => $w->phone])->all(),
            'users' => User::query()->active()->whereNotNull('pin')->orderBy('name')->get()
                ->filter(fn (User $u) => $u->can('wallet.confirm') && $u->accessibleLocations()->whereKey($device->location_id)->exists())
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
            'targets' => collect(WalletTarget::cases())->map(fn (WalletTarget $t) => ['value' => $t->value, 'label' => $t->getLabel()])->all(),
            'poll_seconds' => 20,
        ];
    }

    /** @return array<string, mixed> */
    private function present(WalletTransaction $t, bool $withSuggestions = false): array
    {
        return [
            'id' => $t->id,
            'status' => $t->status->value,
            'status_label' => $t->status->getLabel(),
            'provider' => $t->provider?->value,
            'provider_label' => $t->provider?->getLabel(),
            'direction' => $t->direction?->value,
            'amount' => $t->amount,
            'fee' => $t->fee,
            'balance' => $t->balance,
            'counterparty_phone' => $t->counterparty_phone,
            'counterparty_name' => $t->counterparty_name,
            'reference' => $t->reference,
            'occurred_at' => ($t->occurred_at ?? $t->received_at)->toIso8601String(),
            'wallet' => $t->wallet ? ['id' => $t->wallet->id, 'name' => $t->wallet->name] : null,
            'body' => $t->body,
            'payment_number' => $t->payment?->number,
            'confirmed_by' => $t->confirmer?->name,
            'confirmed_at' => $t->confirmed_at?->toIso8601String(),
            'reject_reason' => $t->reject_reason,
            'suggestions' => $withSuggestions && $t->status === Status::Pending
                ? array_map(fn ($s) => [...$s, 'target' => $s['target']->value], app(WalletSuggestions::class)->for($t)) : [],
        ];
    }

    private function device(Request $request): WalletDevice
    {
        $device = Auth::guard('sanctum')->user(); // the phone, not a User
        abort_unless($device instanceof WalletDevice, 401);

        return $device;
    }

    /** @return Builder<WalletTransaction> transactions of the device's branch */
    private function scope(WalletDevice $device): Builder
    {
        return WalletTransaction::query()->where('location_id', $device->location_id);
    }

    private function authorizeTransaction(Request $request, WalletTransaction $transaction): void
    {
        abort_unless($transaction->location_id === $this->device($request)->location_id, 404);
    }

    /** An employee proves who they are on the shared phone with their PIN (5 tries a minute per user). */
    private function userByPin(Request $request, int $userId, string $pin): User
    {
        $key = 'wallet-pin:'.$userId;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, __('wallet.errors.too_many_attempts'));
        $user = User::query()->active()->find($userId);
        if (! $user || $user->pin === null || ! Hash::check($pin, $user->pin)) {
            RateLimiter::hit($key);
            abort(422, __('wallet.errors.wrong_pin'));
        }
        RateLimiter::clear($key);
        app(CauserResolver::class)->setCauser($user); // what follows in this request is done by this employee

        return $user;
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function domain(\Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $e) {
            abort(422, $e->getMessage());
        }
    }
}
