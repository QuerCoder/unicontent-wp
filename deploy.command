#!/bin/zsh
cd /Users/user/Documents/PhpStorm/unicontent/modules/wordpress

# Читаем версию из плагина
VERSION=$(grep "^\s*\*\s*Version:" unicontent-ai-generator.php | head -1 | sed 's/.*Version: *//' | tr -d '[:space:]')
echo "📦 Версия: $VERSION"

# Коммитим и пушим
git add -A
git commit -m "Release v$VERSION" 2>/dev/null || echo "Нечего коммитить"
git push origin main

# Создаём тег
git tag "v$VERSION" 2>/dev/null && git push origin "v$VERSION" || echo "Тег уже существует"

# Собираем ZIP с правильной структурой (папка unicontent-ai-generator внутри)
ZIP_PATH="/tmp/unicontent-ai-generator-$VERSION.zip"
rm -f "$ZIP_PATH"
rm -rf "/tmp/unicontent-ai-generator"
cp -r . "/tmp/unicontent-ai-generator"
rm -rf "/tmp/unicontent-ai-generator/.git" "/tmp/unicontent-ai-generator/deploy.command" "/tmp/unicontent-ai-generator/.DS_Store"
(cd /tmp && zip -r "$ZIP_PATH" "unicontent-ai-generator" --exclude "*.DS_Store" > /dev/null)
rm -rf "/tmp/unicontent-ai-generator"

# Создаём релиз на GitHub
gh release create "v$VERSION" \
  "$ZIP_PATH#unicontent-ai-generator.zip" \
  --repo QuerCoder/unicontent-wp \
  --title "v$VERSION" \
  --notes "Version $VERSION" 2>&1

echo ""
echo "✅ Плагин v$VERSION запушен и релиз создан на GitHub"
echo "Нажмите Enter для закрытия..."
read
