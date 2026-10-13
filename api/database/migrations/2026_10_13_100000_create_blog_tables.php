<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // The public URL segment. Unique platform-wide: unlike events, blog
            // categories have no organization to scope them.
            $table->string('slug')->unique();
            $table->string('description', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('blog_category_id')->nullable()
                // Deleting a category keeps its posts; they just become
                // uncategorised. Same precedent as the retired `basic` plan:
                // content outlives the taxonomy that once pointed at it.
                ->constrained('blog_categories')->nullOnDelete();
            // Who wrote it. Null once that account is gone — the article stays,
            // the byline falls back to the stored author_name snapshot.
            $table->foreignUuid('author_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('excerpt', 500);
            // Sanitised HTML from the admin editor. Never rendered raw from the
            // request: HtmlSanitizer runs on write, so what is stored is already
            // what is safe to print.
            $table->longText('body');
            $table->string('cover_url')->nullable();
            // Draft rows are invisible publicly, like `is_active` elsewhere —
            // but a date, because scheduling an article is the normal case.
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('read_minutes')->default(1);
            $table->unsignedBigInteger('views')->default(0);
            $table->timestamps();

            // The public list filters on published_at and sorts by it.
            $table->index(['published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('blog_categories');
    }
};
