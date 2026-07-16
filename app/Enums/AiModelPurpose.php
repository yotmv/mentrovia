<?php

namespace App\Enums;

enum AiModelPurpose: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case ImagePrompt = 'image_prompt';
    case Image = 'image';
    case Auto = 'auto';
}
