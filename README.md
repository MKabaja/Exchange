# Exchange

## About

Exchange is a currency exchange platform where clients can hold accounts with **multi-currency wallets** and convert
funds between supported currencies.

The company earns revenue on every exchange through a **spread** — the difference between the market rate and the rate
offered to the client. The spread is calculated dynamically based on the liquidity of the traded currency pair:

- A **base spread of 0.5%** is applied to every exchange.
- Each currency has a **liquidity score** (USD = 1.00 being the most liquid, HUF = 0.40 the least). Less liquid pairs
  receive a higher spread, because they carry more risk and are harder to hedge.
- The formula: `spread = price × (0.5% ÷ average pair liquidity)`

**Example:** exchanging PLN (liquidity 0.55) to HUF (liquidity 0.40) gives an average pair liquidity of 0.475, so the
spread applied is `0.5% ÷ 0.475 ≈ 1.05%` of the transaction value — compared to only `0.5% ÷ 0.975 ≈ 0.51%` for a
USD/EUR pair.

Earnings across all wallets are tracked via the `app:company-wallet` console command.

> **Note:** The original README (before code review) is preserved at [`README_OLD.md`](./README_OLD.md).

---

## Prerequisites

Make sure you have [Docker](https://www.docker.com/get-started) and [Docker Compose](https://docs.docker.com/compose/)
installed on your machine.

> **Warning:** Check that you don't have any other services running on port **80** before starting the containers.

---

## Getting started

### 1. Start the containers

Run this command from the project root directory:

```bash
docker compose up -d
```

### 2. Install dependencies

Once the containers are up, install PHP dependencies inside the container:

```bash
docker exec -it php-fpm composer install
```

### 3. Run database migrations

Apply the database schema:

```bash
docker exec -it php-fpm php bin/console doctrine:migrations:migrate
```

### 4. Open the application

The application is available at:

```
http://localhost
```

---

## Running commands inside the PHP container

All PHP/Symfony commands must be run inside the `php-fpm` container. The general pattern is:

```bash
docker exec -it php-fpm <command>
```

**Example** — running tests:

```bash
docker exec -it php-fpm composer tests
```

---

## API Endpoints

All endpoints require Bearer token authentication. Obtain a token with `app:create-user`.

| Method   | Path                        | Description                                                                                                                                                                             |
|----------|-----------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `GET`    | `/api/wallets`              | List all wallets belonging to the authenticated user.                                                                                                                                   |
| `POST`   | `/api/wallets`              | Create a new wallet. Body: `{ "currency": "PLN" }`. Supported currencies: `PLN`, `EUR`, `USD`, `GBP`, `JPY`, `CHF`, `HUF`. Returns `409` if a wallet for that currency already exists. |
| `POST`   | `/api/wallets/{id}/deposit` | Deposit funds into a wallet. Body: `{ "amount": "500.00" }`. Maximum single deposit: `10000`. Returns `422` if the wallet is blocked.                                                   |
| `POST`   | `/api/wallets/transfer`     | Transfer funds between two wallets of the authenticated user (currency exchange supported). Body: `{ "fromWalletId": 1, "toWalletId": 2, "amount": "100.00" }`.                         |
| `DELETE` | `/api/wallets/{id}`         | Delete a wallet. Returns `204` on success, `422` if the wallet has a non-zero balance or pending transactions, `404` if not found.                                                      |

A ready-to-use Postman collection is available at [`exchange-api.postman_collection.json`](./exchange-api.postman_collection.json).
Set the `authToken` variable to the token returned by `app:create-user`.

---

## Available console commands

| Command                    | Description                                                                                       |
|----------------------------|---------------------------------------------------------------------------------------------------|
| `app:create-user`          | Creates a user and returns an API token. Use this token when testing endpoints (e.g. in Postman). |
| `app:process-transactions` | Processes pending transactions — either approves or rejects them.                                 |
| `app:company-wallet`       | Displays the company wallets and shows how much the company has earned.                           |

**How to run a console command:**

```bash
docker exec -it php-fpm php bin/console <command-name>
```

**Example:**

```bash
docker exec -it php-fpm php bin/console app:create-user
```

---

## Code review — bugs found and fixed

This project underwent a full code audit. Below is a summary of everything that was found and corrected, organized by branch.

### Branch structure

| Branch | Status | Description |
|--------|--------|-------------|
| `main` | base | Original codebase with bugs |
| `fix/bugs` | merged to `main` via PR #1 | Code audit — all bugs found and fixed, one commit per bug |
| `feat/delete-wallet` | current | New `DELETE /api/wallets/{id}` endpoint + regression fix |

---

### `fix/bugs` — bugs found and fixed

#### [1] `wallets.balance` — `DOUBLE` instead of `DECIMAL(15,4)` · **severity: high**

**File:** `migrations/Version20260519090000.php`

The `balance` column used the `DOUBLE` floating-point type. All other financial columns in the schema (`transactions`, `company_wallets`) correctly used `DECIMAL(15,4)`. Floating-point arithmetic cannot represent decimal fractions exactly (e.g. `0.1 + 0.2 ≠ 0.3`), which causes rounding errors that accumulate across operations.

**Fix:** Added a new migration with `ALTER TABLE wallets MODIFY balance DECIMAL(15,4)`.

**Commit:** `fix: change wallets.balance from DOUBLE to DECIMAL(15,4)`

---

#### [2] No balance guard in `TransferService` — balance could go negative · **severity: high**

**File:** `src/Service/TransferService.php`

`TransferService::transfer()` did not check whether the source wallet had sufficient funds before deducting. The balance was modified unconditionally, allowing it to drop below zero.

**Verified:** A transfer of 800 PLN from a wallet with 700 PLN balance succeeded, leaving the wallet at −100 PLN.

**Fix:** Added a pre-transfer check; throws `InsufficientFundsException` (HTTP 422) when `balance < amount`.

**Commit:** `fix: add insufficient funds guard in TransferService`

---

#### [3] `lastActivityAt` not updated on transfer · **severity: medium**

**File:** `src/Service/TransferService.php`

`DepositService` correctly called `setLastActivityAt()` after every operation. `TransferService` did not — neither the source nor the destination wallet had their timestamp updated when a transfer was made.

**Fix:** Added `setLastActivityAt(new DateTimeImmutable())` for both wallets before saving.

**Commit:** `fix: add lastActivityAt on transfer and reject`

---

#### [4+5] Double-debit + `reject()` did not reverse balances · **severity: high**

**Files:** `src/Service/TransferService.php`, `src/Service/TransactionProcessorService.php`

Two related bugs:

- `TransferService::transfer()` modified both wallet balances immediately on transfer creation.
- `TransactionProcessorService::complete()` then modified them **a second time** when the transaction was processed — causing a double-debit on every transaction.
- `TransactionProcessorService::reject()` only changed the transaction status and did nothing to wallet balances. If a `FRAUD_REVIEW` transaction was rejected, the funds already moved in `TransferService` were never returned — making the anti-fraud mechanism non-functional.

**Fix (in `fix/bugs`):** Removed balance mutations from `TransferService` — only `complete()` was supposed to change balances. This was later corrected (see regression below).

**Commits:** `fix: remove balance mutation from TransferService`

---

#### [6] Failing test: `testTransferSuccessfully` · **severity: medium**

**File:** `tests/Service/TransferServiceTest.php`

The test asserted `expects($this->never())->method('setBalance')` on both wallets but the production code called `setBalance`. The test was correct; the code was wrong.

**Fix:** Updated tests to reflect corrected behavior.

**Commit:** `fix: repair failing tests and add missing cases`

---

#### [7] Failing test: `testRejectSetsRejectedStatus` · **severity: medium**

**File:** `tests/Service/TransactionProcessorServiceTest.php`

The test expected `reject()` to call `findById()` and `save()` on the wallet repository (updating `lastActivityAt`), but the original `reject()` implementation did nothing with wallets.

**Fix:** Added `lastActivityAt` update in `reject()` and aligned test expectations.

**Commit:** `fix: add lastActivityAt on transfer and reject`

---

### `feat/delete-wallet` — new endpoint + regression fix

#### New endpoint: `DELETE /api/wallets/{id}`

Implemented a new endpoint for deleting a user's own wallet.

**Business rules:**
- Returns `204 No Content` on success.
- Returns `422` if the wallet has a non-zero balance (`WalletNotEmptyException`).
- Returns `422` if the wallet has any `PENDING` or `FRAUD_REVIEW` transactions (`WalletHasPendingTransactionsException`).
- Returns `404` if the wallet does not exist or belongs to another user.

**Commits:**
- `feat: add wallet delete exceptions`
- `feat: add deleteWallet to WalletService`
- `feat: add DELETE /api/wallets/{id} endpoint`
- `feat: add tests for delete wallet`

---

#### Regression fix: double-debit fix was applied in the wrong place · **severity: high**

During manual testing on `feat/delete-wallet`, it was discovered that wallet balances did not change immediately after creating a transfer — they only changed after running `app:process-transactions`.

**Root cause:** The `fix/bugs` fix for double-debit removed balance mutations from `TransferService` instead of from `TransactionProcessorService::complete()`. This meant balances were only updated when the processor ran, which:
- Made `FRAUD_REVIEW` transactions not actually hold funds (anti-fraud broken again)
- Allowed multiple pending transfers to be created that together exceed the available balance

**Correct fix:**
- `TransferService::transfer()` — balance mutations restored (source decremented, destination incremented immediately at creation)
- `TransactionProcessorService::complete()` — balance mutations removed (only updates transaction status and `lastActivityAt`)
- `TransactionProcessorService::reject()` — added full balance reversal for both wallets (source `+= fromAmount`, destination `-= toAmount`), with null guards for deleted wallets

**Commit:** `fix: apply balance changes immediately on transfer creation`

---

### Test changes summary

| Test file | Change |
|-----------|--------|
| `TransferServiceTest` | Replaced `testTransferDoesNotMutateWalletBalances` (old, incorrect expectation) with `testTransferMutatesWalletBalancesImmediately` (asserts immediate balance change) |
| `TransactionProcessorServiceTest` | Removed balance assertions from `testCompleteSetsStatusAndUpdatesActivity` (complete no longer changes balances); updated all `reject()` tests to use `willReturnMap` for both wallets; added `testRejectReversesBothWalletBalances`; renamed `testRejectDoesNotSaveWalletWhenFromWalletNotFound` to `testRejectDoesNotSaveWalletWhenBothWalletsNotFound`; updated `testCompleteRejectsWhenFromWalletNotFound` to assert toWallet balance reversal |

**Final test count: 109 tests, 289 assertions, 0 failures.**
