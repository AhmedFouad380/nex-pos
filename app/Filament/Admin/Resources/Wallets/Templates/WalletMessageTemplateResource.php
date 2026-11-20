<?php

namespace App\Filament\Admin\Resources\Wallets\Templates;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Wallet\Enums\WalletProvider;
use App\Domain\Wallet\Models\WalletMessageTemplate;
use App\Domain\Wallet\Services\TemplateCompiler;
use App\Filament\Admin\Resources\Wallets\Templates\Pages\ManageWalletMessageTemplates;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use App\Support\DomainException;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** How each provider's messages are worded — editable here, with a live "test with a real message" box. */
class WalletMessageTemplateResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = WalletMessageTemplate::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Wallets;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'wallet-templates';

    public static function getModelLabel(): string
    {
        return __('Message template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Message templates');
    }

    protected static function permissionFor(string $action): string|false
    {
        return in_array($action, ['viewAny', 'view'], true) ? 'wallet.view' : 'wallet.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('provider')->label(__('Provider'))->options(WalletProvider::class)->required(),
            Select::make('direction')->label(__('Direction'))->options(PaymentDirection::class)->required(),
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            TextInput::make('sender')->label(__('Sender'))->maxLength(60)
                ->helperText(__('The SMS sender name exactly as the phone shows it (e.g. VF-Cash). Phones only forward messages from these senders.')),
            TextInput::make('date_format')->label(__('Date format'))->default('H:i y-m-d')->required()->maxLength(30)
                ->helperText(__('How {datetime} is written. "17:20 26-11-16" is H:i y-m-d.')),
            TextInput::make('sort')->label(__('Order'))->numeric()->integer()->default(0),
            Textarea::make('template')->label(__('Template'))->required()->rows(5)->columnSpanFull()->live(onBlur: true)
                ->helperText(new HtmlString(e(__('Paste a real message and replace each value with:')).' <code>{amount}</code> <code>{fee}</code> <code>{balance}</code> '
                    .'<code>{phone}</code> <code>{name}</code> <code>{wallet}</code> <code>{datetime}</code> <code>{reference}</code> — '
                    .e(__('and any text that changes (ads, links) with')).' <code>{*}</code>'))
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                    try {
                        app(TemplateCompiler::class)->compile((string) $value);
                    } catch (DomainException $e) {
                        $fail($e->getMessage());
                    }
                }),
            Toggle::make('is_active')->label(__('Active'))->default(true),
            Section::make(__('Test with a real message'))->columnSpanFull()->schema([
                Textarea::make('sample')->hiddenLabel()->rows(5)->live(onBlur: true)->dehydrated(false)
                    ->placeholder(__('Paste a message here to see what the template reads from it.')),
                TextEntry::make('result')->hiddenLabel()->state(fn (Get $get) => self::preview((string) $get('template'), (string) $get('sample'), (string) $get('date_format'))),
            ]),
        ]);
    }

    private static function preview(string $template, string $sample, string $format): HtmlString|string
    {
        if (trim($sample) === '' || trim($template) === '') {
            return '—';
        }
        try {
            $parsed = app(TemplateCompiler::class)->parse(new WalletMessageTemplate(['template' => $template, 'date_format' => $format ?: 'H:i y-m-d']), $sample);
        } catch (DomainException $e) {
            return new HtmlString('<span style="color:rgb(220 38 38)">'.e($e->getMessage()).'</span>');
        }
        if ($parsed === null) {
            return new HtmlString('<span style="color:rgb(220 38 38)">'.e(__('The template does not match this message.')).'</span>');
        }
        $rows = collect($parsed->toArray())->map(fn ($value, $key) => '<tr><td style="padding:2px 12px 2px 0"><code>{'.e($key).'}</code></td><td><strong>'
            .e($value ?? '—').'</strong></td></tr>')->implode('');

        return new HtmlString('<span style="color:rgb(22 163 74)">✔ '.e(__('Matches')).'</span><table>'.$rows.'</table>');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->weight('bold')->searchable(),
                TextColumn::make('provider')->label(__('Provider'))->badge(),
                TextColumn::make('direction')->label(__('Direction'))->badge(),
                TextColumn::make('sender')->label(__('Sender'))->placeholder(__('Any')),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWalletMessageTemplates::route('/')];
    }
}
