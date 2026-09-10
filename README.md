# Reply Center — тестовый стенд

Задание: см. [TASK.md](TASK.md). Как всё устроено — [ARCHITECTURE.md](ARCHITECTURE.md).

## Запуск

Нужен Docker с плагином compose.

```bash
make up
```

Команда поднимет Postgres 16 и PHP 8.3, поставит зависимости, накатит миграции и засеет демо-данные.

## Команды

```bash
make test      # прогнать тесты
make migrate   # пересоздать БД и засеять заново
make shell     # bash внутри контейнера приложения
make down      # остановить и удалить тома
```

Postgres доступен снаружи на `localhost:55432` (`app` / `secret`, база `reply_center`) — если удобнее смотреть данные своим клиентом.

## Что где лежит

```
app/
  Contracts/SentimentClassifier.php     интерфейс классификатора
  Services/FakeFlakyClassifier.php      фейк вместо LLM
  Services/MailGateway.php              заглушка отправки почты
  Support/IdempotencyGuard.php          дедупликация событий шины
  Jobs/ProcessInboundReplyJob.php       ← здесь работа
  Jobs/SendCampaignStepJob.php          отправка шага кампании
  Models/                               Client, CampaignEnrollment, ReplyTask, ProcessedEvent
database/
  migrations/                           схема
  seeders/DemoSeeder.php                демо-данные под фикстуру
tests/
  Fixtures/inbound_events.json          12 событий, снятых с прода
  Feature/SmokeTest.php                 проверка, что стенд живой
```

## Если что-то не поднялось

Напишите нам — чинить окружение это не часть задания, и время на это мы не засчитываем.
