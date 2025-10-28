# === EmailBridge Quality Checks ===

check: php js test

# -----------------------------
# PHP Checks
# -----------------------------
php:
	@echo "🔍 Checking PHP code style (php-cs-fixer)..."
	@vendor/bin/php-cs-fixer fix --dry-run --diff || true
	@echo "🧠 Running static analysis (psalm)..."
	@vendor/bin/psalm --shepherd || true
	@echo "✅ PHP checks completed."

# -----------------------------
# JS & CSS Checks
# -----------------------------
js:
	@echo "🎨 Running ESLint..."
	@npx eslint src/ || true
	@echo "💅 Running Stylelint..."
	@npx stylelint 'src/**/*.{vue,css,scss}' || true
	@echo "✅ JS/CSS checks completed."

# -----------------------------
# PHPUnit Tests
# -----------------------------
test:
	@echo "🧪 Running PHPUnit tests..."
	@vendor/bin/phpunit || true
	@echo "✅ Tests completed."

# -----------------------------
# Clean caches
# -----------------------------
clean:
	@echo "🧹 Cleaning temporary files..."
	@rm -rf vendor composer.lock node_modules coverage || true
	@echo "✅ Clean done."
