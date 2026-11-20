<?php

declare(strict_types=1);

namespace App\Domain\Finance\Controllers;

use App\Domain\Assistant\Models\AssistantDiscordUser;
use App\Domain\Assistant\Services\AssistantService;
use App\Domain\Finance\Models\DiscordExpenseReceipt;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Services\DiscordReceiptService;
use App\Domain\Finance\Services\ExpenseService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DiscordExpenseReceiptController extends ApiController
{
    private const DISCORD_ID = 'regex:/^\d{17,20}$/';

    public function __construct(
        private readonly AssistantService $assistant,
        private readonly DiscordReceiptService $receipts,
        private readonly ExpenseService $expenses,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $actor = $this->discordActor($request);
        $input = $request->validate([
            'attachment_id' => ['required', 'string', self::DISCORD_ID],
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
        $image = $input['image'];
        $bytes = $image->get();
        $receipt = DiscordExpenseReceipt::query()->firstOrCreate(
            ['attachment_id' => $input['attachment_id']],
            [
                'discord_user_id' => $actor->discord_id,
                'channel_id' => $request->input('channel_id'),
                'filename' => mb_substr($image->getClientOriginalName(), 0, 255),
                'mime' => $image->getMimeType(),
                'original' => base64_encode($bytes),
                'fingerprint' => hash('sha256', $bytes),
                'status' => 'pending',
                'revision' => 0,
                'fields' => ['pay_schedule' => 'on_receipt'],
                'history' => [],
            ],
        );
        $this->authorizeReceipt($receipt, $actor, $request);

        return $this->payload($this->receipts->preview($receipt, $actor->may_confirm_receipts));
    }

    public function review(Request $request, string $receipt): JsonResponse
    {
        $actor = $this->discordActor($request);
        $draft = DiscordExpenseReceipt::query()->findOrFail($receipt);
        $this->authorizeReceipt($draft, $actor, $request);

        return $this->payload($this->receipts->preview($draft, $actor->may_confirm_receipts));
    }

    public function decide(Request $request, string $receipt): JsonResponse
    {
        $actor = $this->discordActor($request);
        $input = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'action' => ['required', 'in:update,confirm,cancel'],
            'fields' => ['required_if:action,update', 'array:'.implode(',', DiscordExpenseReceipt::FIELDS)],
            'fields.*' => ['nullable', 'string', 'max:255'],
        ]);
        if ($input['action'] === 'confirm') {
            abort_unless($actor->may_confirm_receipts, 403, 'This Discord account cannot confirm expenses.');
        }

        $draft = DB::transaction(function () use ($actor, $request, $receipt, $input): DiscordExpenseReceipt {
            $draft = DiscordExpenseReceipt::query()->lockForUpdate()->findOrFail($receipt);
            $this->authorizeReceipt($draft, $actor, $request);
            abort_if($draft->revision !== (int) $input['revision'], 409, 'The preview changed. Review the latest version.');

            if ($draft->status !== 'pending') {
                abort_unless(
                    ($draft->status === 'archived' && $input['action'] === 'cancel')
                        || ($draft->status === 'confirmed' && $input['action'] === 'confirm'),
                    409,
                    'This receipt has already been resolved.',
                );

                return $draft;
            }

            if ($input['action'] === 'update') {
                $this->receipts->update($draft, [...$draft->fields, ...$input['fields']]);
            } elseif ($input['action'] === 'cancel') {
                $draft->update(['status' => 'archived', 'archived_at' => now()]);
            } else {
                $data = $this->receipts->expense($draft);
                try {
                    $draft->update(['posted_fingerprint' => $draft->fingerprint]);
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages(['receipt' => 'This exact receipt image was already recorded.']);
                }
                $expense = $this->expenses->record($data, $request->user()->id);
                $draft->update(['status' => 'confirmed', 'expense_id' => $expense->getKey()]);
            }

            return $draft;
        });

        return $this->payload($this->receipts->preview($draft, $actor->may_confirm_receipts));
    }

    public function archive(Request $request): JsonResponse
    {
        $page = DiscordExpenseReceipt::query()
            ->select('id', 'filename', 'status', 'expense_id', 'fields', 'history', 'created_at', 'archived_at')
            ->latest()->orderByDesc('id')->paginate($this->perPage($request));

        return $this->payload($page->items(), [
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    public function original(string $receipt): StreamedResponse
    {
        $draft = DiscordExpenseReceipt::query()->findOrFail($receipt);

        return response()->streamDownload(
            static function () use ($draft): void {
                echo base64_decode($draft->original, true);
            },
            $draft->filename,
            ['Content-Type' => $draft->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function archivedExpenses(Request $request): JsonResponse
    {
        $page = Expense::onlyTrashed()
            ->select('id', 'payee', 'reference', 'date', 'amount_cents', 'currency', 'note', 'deleted_at')
            ->latest('deleted_at')->orderByDesc('id')->paginate($this->perPage($request));

        return $this->payload($page->items(), [
            'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]);
    }

    private function authorizeReceipt(DiscordExpenseReceipt $receipt, AssistantDiscordUser $actor, Request $request): void
    {
        abort_unless($receipt->discord_user_id === $actor->discord_id && $receipt->channel_id === $request->input('channel_id'), 404);
    }

    private function discordActor(Request $request): AssistantDiscordUser
    {
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $this->assistant->isBotToken($token)
            && $request->user()->hasPermission('expenses.manage'), 403);
        $identity = $request->validate([
            'discord_user_id' => ['required', 'string', self::DISCORD_ID],
            'channel_id' => ['required', 'string', self::DISCORD_ID],
        ]);
        $settings = $this->assistant->settings();
        abort_unless($settings->enabled && $settings->discord_finance_channel_id === $identity['channel_id'], 403);

        return AssistantDiscordUser::query()->where('discord_id', $identity['discord_user_id'])->firstOrFail();
    }
}
