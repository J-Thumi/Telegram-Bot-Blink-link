<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubjectResource\Pages;
use App\Filament\Resources\SubjectResource\RelationManagers\ImagesRelationManager;
use App\Models\Subject;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Tabs\Tab;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;

class SubjectResource extends Resource
{
    protected static ?string $model = Subject::class;
    protected static ?string $navigationIcon = 'heroicon-o-folder';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $state, Forms\Set $set) => 
                        $set('slug', \Illuminate\Support\Str::slug($state))
                    ),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(Subject::class, 'slug', ignoreRecord: true),
                Forms\Components\TextInput::make('members_count')
                    ->label('Telegram Members')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->helperText('How many members should be in the Telegram channel for this subject for the images to be sent'),
                Forms\Components\DateTimePicker::make('due_date')
                    ->label('Send Images By')
                    ->helperText('By when should the images be sent to the Telegram channel for this subject?'),
                Forms\Components\Select::make('status')
                    ->label('Telegram Status')
                    ->options([
                        'pending' => 'Pending',
                        'sent' => 'Sent',
                        'failed' => 'Failed',
                        'not_due' => 'Not Due',
                    ])
                    ->default('pending')
                    ->helperText('Current status of the Telegram channel for this subject'),
                Forms\Components\Textarea::make('description')
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Forms\Components\ColorPicker::make('color')
                    ->label('Subject Color')
                    ->helperText('Choose a color for this subject category'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Telegram Status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'pending' => 'warning',
                        'not_due' => 'secondary',
                        default => 'secondary',
                    }),
                Tables\Columns\TextColumn::make('members_count')
                    ->label('Required Members')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('images_count')
                    ->counts('images')
                    ->label('Total Images')
                    ->badge()
                    ->color('success'),
                Tables\Columns\TextColumn::make('due_date')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('images_sent_at')
                    ->label('Images Sent')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'sent' => 'Sent',
                        'failed' => 'Failed',
                        'not_due' => 'Not Due',
                    ]),
                Tables\Filters\Filter::make('due_date')
                    ->form([
                        Forms\Components\DatePicker::make('due_from')
                            ->label('Due From'),
                        Forms\Components\DatePicker::make('due_until')
                            ->label('Due Until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['due_from'], fn ($query) => $query->whereDate('due_date', '>=', $data['due_from']))
                            ->when($data['due_until'], fn ($query) => $query->whereDate('due_date', '<=', $data['due_until']));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                
                // Add the custom button here
                // In SubjectResource.php, replace the sendToTelegram action with this:

            Tables\Actions\Action::make('sendToTelegram')
                ->label('Send to Telegram')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('📤 Confirm Send to Telegram')
                ->modalDescription(function (Subject $record) {
                    $imageCount = $record->images()->count();
                    $imagePaths = $record->images()->pluck('title')->take(3)->toArray();
                    $moreImages = $imageCount > 3 ? " and {$imageCount} more" : "";
                    
                    return "⚠️ **You are about to send {$imageCount} image(s) to Telegram:**\n\n" .
                        "**Subject:** {$record->name}\n" .
                        "**Images to send:** " . implode(', ', $imagePaths) . "{$moreImages}\n\n" .
                        "**📌 Important Precautions:**\n" .
                        "• ✅ This will send ALL images to the Telegram channel\n" .
                        "• ⚠️ No member count verification will be performed\n" .
                        "• 📸 Images will be sent in their current sort order\n" .
                        "• 🔄 This action cannot be undone\n" .
                        "• 📝 This action will be logged\n\n" .
                        "⚠️ **Are you absolutely sure you want to proceed?**";
                })
                ->modalSubmitActionLabel('✅ Yes, Send Images Now')
                ->modalCancelActionLabel('❌ No, Cancel')
                ->modalWidth('lg')
                ->action(function (Subject $record) {
                    return self::sendToTelegram($record);
                })
                ->visible(fn (Subject $record): bool => 
                    $record->images()->exists()
                ),
                    
                // Button to check status only
                Tables\Actions\Action::make('checkStatus')
                    ->label('Check Status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->action(function (Subject $record) {
                        return self::checkSubjectStatus($record);
                    }),
                    
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('bulkSendToTelegram')
                        ->label('Send Selected to Telegram')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $sent = 0;
                            $failed = 0;
                            
                            foreach ($records as $record) {
                                $result = self::sendToTelegram($record, false);
                                if ($result) {
                                    $sent++;
                                } else {
                                    $failed++;
                                }
                            }
                            
                            Notification::make()
                                ->title("Processed {$records->count()} subjects")
                                ->body("✅ Sent: {$sent}\n❌ Failed: {$failed}")
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

   /**
     * Send subject to Telegram with detailed confirmation
     */
    public static function sendToTelegram(Subject $subject, $showNotification = true)
    {
        // Get subject details for the confirmation
        $imageCount = $subject->images()->count();
        $imagePreview = $subject->images()->first();
        
        // Create a detailed confirmation notification
        Notification::make()
            ->title('⚠️ Confirm Send to Telegram')
            ->body(
                "You are about to send the following subject to Telegram:\n\n" .
                "📚 **Subject:** {$subject->name}\n" .
                "📸 **Images:** {$imageCount} image(s)\n" .
                "📅 **Due Date:** " . ($subject->due_date ? \Illuminate\Support\Carbon::parse($subject->due_date)->format('F j, Y') : 'Not set') . "\n\n" .
                "⚠️ **Important Notes:**\n" .
                "• This will send ALL images to the Telegram channel\n" .
                "• No member count check will be performed\n" .
                "• Images will be sent in order of their sort order\n" .
                "• This action cannot be undone\n\n" .
                "Do you want to proceed?"
            )
            ->warning()
            ->persistent()
            ->actions([
                \Filament\Notifications\Actions\Action::make('confirm')
                    ->label('✅ Yes, Send Now')
                    ->color('success')
                    ->button()
                    ->action(function () use ($subject, $showNotification) {
                        return self::executeSendToTelegram($subject, $showNotification);
                    }),
                \Filament\Notifications\Actions\Action::make('cancel')
                    ->label('❌ Cancel')
                    ->color('danger')
                    ->button(),
            ])
            ->send();
    }

    /**
     * Execute the actual sending
     */
    public static function executeSendToTelegram(Subject $subject, $showNotification = true)
    {
        try {
            // Run the artisan command for this specific subject
            $exitCode = \Illuminate\Support\Facades\Artisan::call('subjects:send-specific', [
                'subject_id' => $subject->id,
            ]);
            
            $output = \Illuminate\Support\Facades\Artisan::output();
            
            if ($exitCode === 0) {
                if ($showNotification) {
                    Notification::make()
                        ->title('✅ Success!')
                        ->body("Images for '{$subject->name}' have been sent to Telegram successfully!")
                        ->success()
                        ->send();
                }
                
                // Refresh the page to show updated status
                if (request()->routeIs('filament.resources.subjects.*')) {
                    \Filament\Notifications\Notification::make()
                        ->title('Success!')
                        ->body('Refresh the page to see updated status.')
                        ->success()
                        ->send();
                }
                
                return true;
            } else {
                if ($showNotification) {
                    Notification::make()
                        ->title('❌ Failed')
                        ->body("Failed to send images for '{$subject->name}'. Check logs for details.")
                        ->danger()
                        ->send();
                }
                return false;
            }
        } catch (\Exception $e) {
            if ($showNotification) {
                Notification::make()
                    ->title('❌ Error')
                    ->body("Error: " . $e->getMessage())
                    ->danger()
                    ->send();
            }
            return false;
        }
    }
    /**
     * Check subject status
     */
    public static function checkSubjectStatus(Subject $subject)
    {
        try {
            $telegram = new \App\Services\TelegramService();
            $channelId = config('services.telegram.channel_id');
            $memberCount = $telegram->getChatMemberCount($channelId);
            
            $status = [
                'subject' => $subject->name,
                'current_members' => $memberCount,
                'required_members' => $subject->members_count,
                'meets_requirement' => $memberCount >= $subject->members_count,
                'has_images' => $subject->images()->exists(),
                'images_count' => $subject->images()->count(),
                'due_date' => $subject->due_date?->format('Y-m-d H:i:s'),
                'is_due' => $subject->due_date && $subject->due_date <= now()->addHours(24),
                'status' => $subject->status,
                'images_sent_at' => $subject->images_sent_at?->format('Y-m-d H:i:s'),
            ];
            
            $message = "📊 **Subject Status: {$subject->name}**\n\n";
            $message .= "👥 Members: {$memberCount} / {$subject->members_count}\n";
            $message .= "✅ Requirement met: " . ($status['meets_requirement'] ? 'Yes' : 'No') . "\n";
            $message .= "🖼️ Images: {$status['images_count']}\n";
            $message .= "📅 Due date: " . ($status['due_date'] ?? 'Not set') . "\n";
            $message .= "⏰ Is due: " . ($status['is_due'] ? 'Yes' : 'No') . "\n";
            $message .= "📤 Status: {$subject->status}\n";
            $message .= "📨 Last sent: " . ($status['images_sent_at'] ?? 'Never');
            
            Notification::make()
                ->title('Subject Status')
                ->body($message)
                ->info()
                ->duration(10000) // 10 seconds
                ->send();
                
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error checking status')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function getRelations(): array
    {
        return [
            ImagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubjects::route('/'),
            'create' => Pages\CreateSubject::route('/create'),
            'edit' => Pages\EditSubject::route('/{record}/edit'),
        ];
    }
}