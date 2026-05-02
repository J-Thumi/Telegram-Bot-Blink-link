<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseResource\Pages;
use App\Filament\Resources\PurchaseResource\RelationManagers;
use App\Models\Purchase;
use App\Models\Invoice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Sales Management';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // No form fields - purchases and invoices should not be created or edited manually
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Purchase Information
                Tables\Columns\TextColumn::make('id')
                    ->label('Purchase ID')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('telegram_id')
                    ->label('Telegram User ID')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('subject')
                    ->label('Subject')
                    ->searchable()
                    ->limit(30),
                
                Tables\Columns\IconColumn::make('has_invite_link_sent')
                    ->label('Invite Sent')
                    ->boolean()
                    ->getStateUsing(fn (Purchase $record) => $record->hasInviteLinkSent())
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
                
                Tables\Columns\TextColumn::make('telegram_invite_link')
                    ->label('Invite Link')
                    ->limit(30)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('invite_sent_at')
                    ->label('Invite Sent At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                // Invoice Information
                Tables\Columns\TextColumn::make('invoice.id')
                    ->label('Invoice ID')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('invoice.blink_id')
                    ->label('Blink ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('invoice.blink_client_ip')
                    ->label('Blink Client IP')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('invoice.telegram_client_ip')
                    ->label('Telegram Client IP')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                                    
                Tables\Columns\TextColumn::make('invoice.amount_in_satoshis')
                    ->label('Amount (sats)')
                    ->money('SATS')
                    ->sortable()
                    ->getStateUsing(fn (Purchase $record) => $record->invoice?->amount_in_satoshis)
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\BadgeColumn::make('invoice.status')
                    ->label('Invoice Status')
                    ->colors([
                        'warning' => Invoice::STATUS_PENDING,
                        'success' => Invoice::STATUS_PAID,
                        'danger' => Invoice::STATUS_EXPIRED,
                        'gray' => Invoice::STATUS_CANCELLED,
                    ])
                    ->sortable(),
                
                Tables\Columns\IconColumn::make('invoice.is_instant_buy')
                    ->label('Instant Buy')
                    ->boolean()
                    ->trueColor('success'),
                
                Tables\Columns\TextColumn::make('invoice.full_name')
                    ->label('Customer Name')
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('invoice.username')
                    ->label('Username')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->getStateUsing(function (Purchase $record): string {
                        // Check if invite has been sent (not null)
                        if ($record->invoice && $record->invoice->is_instant_buy && $record->image_sent_at !== null) {
                            return 'Sent';
                        }
                        
                        // If invite hasn't been sent, check if it's instant buy
                        if ($record->invoice && $record->invoice->is_instant_buy) {
                            return 'Not Sent';
                        }
                        
                        // If invite hasn't been sent and not instant buy
                        return 'To be received in channel';
                    })
                    ->badge()
                    ->colors([
                        'success' => 'Sent',
                        'danger' => 'Not Sent',
                        'warning' => 'To be received in channel',
                    ])
                    ->icon(function (string $state): string {
                        return match ($state) {
                            'Sent' => 'heroicon-o-check-circle',
                            'Not Sent' => 'heroicon-o-x-circle',
                            'To be received in channel' => 'heroicon-o-clock',
                            default => 'heroicon-o-question-mark-circle',
                        };
                    }),
                
                Tables\Columns\TextColumn::make('invoice.satoshis_paid')
                    ->label('Sats Paid')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('invoice.paid_at')
                    ->label('Paid At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                // Timestamps
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Purchase Date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('is_instant_buy')
                    ->label('Instant Buy')
                    ->relationship('invoice', 'is_instant_buy')
                    ->options([
                        '1' => 'Yes',
                        '0' => 'No',
                    ]),
                
                Tables\Filters\Filter::make('has_invite_sent')
                    ->label('Invite Sent')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('telegram_invite_link')->whereNotNull('invite_sent_at')),
                
                Tables\Filters\Filter::make('completed')
                    ->label('Completed (Paid + Invite Sent)')
                    ->query(fn (Builder $query): Builder => $query->completed()),
                
                Tables\Filters\Filter::make('pending')
                    ->label('Pending')
                    ->query(fn (Builder $query): Builder => $query->pending()),
                Tables\Filters\Filter::make('paid')
                    ->label('Paid')
                    ->query(fn (Builder $query): Builder => $query->paid()),
                Tables\Filters\Filter::make('expired')
                    ->label('Expired')
                    ->query(fn (Builder $query): Builder => $query->expired()),
                Tables\Filters\Filter::make('cancelled')
                    ->label('Cancelled')
                    ->query(fn (Builder $query): Builder => $query->cancelled()),
            ])
            ->actions([
                Tables\Actions\Action::make('sendInstantly')
                    ->label('Send Now To this Telegram User')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (Purchase $record): bool => 
                        $record->invoice && 
                        $record->invoice->is_instant_buy &&
                        $record->invoice->isPaid()
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Send Instant Purchase')
                    ->modalDescription('Are you sure you want to send the subject images to this Telegram user instantly?')
                    ->modalSubmitActionLabel('Yes, send now')
                    ->action(function (Purchase $record, TelegramService $telegram): void {
                        try {
                            // Get the next due subject
                            $subject = \App\Models\Subject::getNextDue();
                            
                            if (!$subject) {
                                Notification::make()
                                    ->title('No Subject Available')
                                    ->body('No papers are scheduled for the next 24 hours. Please contact support or wait for the next update.')
                                    ->warning()
                                    ->send();
                                
                                return;
                            }
                            
                            // Send the subject images
                            $telegram->fulfillSubjectImages($record->telegram_id, $subject);
                            
                            // Update the purchase with delivery info
                            $record->update([
                                'image_sent_at' => now(),
                            ]);
                            
                            Notification::make()
                                ->title('Success')
                                ->body("Instant purchase content sent successfully to Telegram user {$record->telegram_id} for subject: {$subject->name}")
                                ->success()
                                ->send();
                                
                        } catch (\Exception $e) {
                            Log::error('Instant purchase failed', [
                                'purchase_id' => $record->id,
                                'telegram_id' => $record->telegram_id,
                                'error' => $e->getMessage()
                            ]);
                            
                            Notification::make()
                                ->title('Error')
                                ->body('Failed to send instant purchase: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                
                // Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchases::route('/'),
            'create' => Pages\CreatePurchase::route('/create'),
            'edit' => Pages\EditPurchase::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // Disable manual creation
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false; // Disable manual editing
    }
}