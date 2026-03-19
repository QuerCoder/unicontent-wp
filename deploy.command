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

# Собираем ZIP
ZIP_PATH="/tmp/unicontent-ai-generator-$VERSION.zip"
zip -r "$ZIP_PATH" . \
  --exclude "*.git*" \
  --exclude "deploy.command" \
  --exclude ".DS_Store" \
  > /dev/null

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
