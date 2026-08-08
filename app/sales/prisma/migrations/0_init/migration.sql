-- CreateSchema
CREATE SCHEMA IF NOT EXISTS "public";

-- CreateEnum
CREATE TYPE "Role" AS ENUM ('SALES', 'COLLECTION', 'MANAGEMENT');

-- CreateEnum
CREATE TYPE "Channel" AS ENUM ('SITE', 'PAMERAN', 'JANJIAN');

-- CreateEnum
CREATE TYPE "StatusLead" AS ENUM ('HOT', 'WARM', 'COLD');

-- CreateEnum
CREATE TYPE "Stage" AS ENUM ('WI', 'FOLLOW_UP', 'NUP', 'UTJ', 'SPR', 'ACC', 'AKAD', 'REALISASI');

-- CreateEnum
CREATE TYPE "CaraBayar" AS ENUM ('KPR', 'CASH');

-- CreateEnum
CREATE TYPE "UnitStatus" AS ENUM ('OPEN_STOCK', 'HOLD', 'PROSES', 'REALISASI', 'CASH_LUNAS', 'BATAL');

-- CreateEnum
CREATE TYPE "BatalKategori" AS ENUM ('UTJ', 'SPR', 'WAITING_LIST');

-- CreateEnum
CREATE TYPE "BatalAlasan" AS ENUM ('KOL_2', 'KOL_5', 'REJECT_BANK', 'BATAL_SEPIHAK', 'MASALAH_KELUARGA', 'SKEMA_BAYAR', 'OTHER');

-- CreateEnum
CREATE TYPE "RefundStatus" AS ENUM ('NONE', 'PENDING', 'DONE');

-- CreateEnum
CREATE TYPE "EventType" AS ENUM ('WI', 'FOLLOW_UP', 'NUP', 'UTJ', 'SPR', 'ACC', 'AKAD', 'REALISASI', 'BATAL', 'WAITING_LIST', 'STATUS_OVERRIDE');

-- CreateEnum
CREATE TYPE "TargetScope" AS ENUM ('ANNUAL', 'MONTHLY', 'TEAM_WEEK', 'TEAM_MONTH');

-- CreateEnum
CREATE TYPE "TargetMetric" AS ENUM ('UNIT', 'NUP', 'SPR');

-- CreateTable
CREATE TABLE "User" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "email" TEXT NOT NULL,
    "passwordHash" TEXT NOT NULL,
    "role" "Role" NOT NULL DEFAULT 'SALES',
    "salesId" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "User_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Project" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "code" TEXT NOT NULL,

    CONSTRAINT "Project_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Cluster" (
    "id" TEXT NOT NULL,
    "projectId" TEXT NOT NULL,
    "name" TEXT NOT NULL,

    CONSTRAINT "Cluster_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Phase" (
    "id" TEXT NOT NULL,
    "clusterId" TEXT NOT NULL,
    "name" TEXT NOT NULL,

    CONSTRAINT "Phase_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "UnitType" (
    "id" TEXT NOT NULL,
    "clusterId" TEXT NOT NULL,
    "name" TEXT NOT NULL,

    CONSTRAINT "UnitType_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Team" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,

    CONSTRAINT "Team_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Sales" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "teamId" TEXT,

    CONSTRAINT "Sales_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Coordinator" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,

    CONSTRAINT "Coordinator_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Agency" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,

    CONSTRAINT "Agency_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "RotationAssignment" (
    "id" TEXT NOT NULL,
    "agencyId" TEXT NOT NULL,
    "coordinatorId" TEXT NOT NULL,
    "effectiveFrom" TIMESTAMP(3) NOT NULL,
    "effectiveTo" TIMESTAMP(3),

    CONSTRAINT "RotationAssignment_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Bank" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,

    CONSTRAINT "Bank_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Promo" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,

    CONSTRAINT "Promo_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Target" (
    "id" TEXT NOT NULL,
    "scope" "TargetScope" NOT NULL,
    "metric" "TargetMetric" NOT NULL,
    "period" TEXT NOT NULL,
    "teamId" TEXT,
    "value" INTEGER NOT NULL,

    CONSTRAINT "Target_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Unit" (
    "id" TEXT NOT NULL,
    "code" TEXT NOT NULL,
    "phaseId" TEXT NOT NULL,
    "unitTypeId" TEXT NOT NULL,
    "block" TEXT,
    "no" TEXT,
    "harga" DECIMAL(65,30),
    "status" "UnitStatus" NOT NULL DEFAULT 'OPEN_STOCK',
    "statusOverride" BOOLEAN NOT NULL DEFAULT false,
    "currentDealId" TEXT,
    "tglSprCetak" TIMESTAMP(3),
    "tglUtj" TIMESTAMP(3),
    "tglAkad" TIMESTAMP(3),

    CONSTRAINT "Unit_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Deal" (
    "id" TEXT NOT NULL,
    "nama" TEXT NOT NULL,
    "channel" "Channel" NOT NULL DEFAULT 'SITE',
    "pameranLokasi" TEXT,
    "sumberInformasi" TEXT,
    "agencyId" TEXT NOT NULL,
    "salesId" TEXT NOT NULL,
    "coordinatorId" TEXT,
    "clusterId" TEXT,
    "phaseId" TEXT,
    "unitTypeId" TEXT,
    "statusLead" "StatusLead" NOT NULL DEFAULT 'COLD',
    "stage" "Stage" NOT NULL DEFAULT 'WI',
    "tanggalWi" TIMESTAMP(3) NOT NULL,
    "fuCount" INTEGER NOT NULL DEFAULT 0,
    "unitId" TEXT,
    "caraBayar" "CaraBayar",
    "bankId" TEXT,
    "promoId" TEXT,
    "tglNup" TIMESTAMP(3),
    "tglUtj" TIMESTAMP(3),
    "tglSpr" TIMESTAMP(3),
    "tglAcc" TIMESTAMP(3),
    "tglAkad" TIMESTAMP(3),
    "tglRealisasi" TIMESTAMP(3),
    "waitingList" BOOLEAN NOT NULL DEFAULT false,
    "batal" BOOLEAN NOT NULL DEFAULT false,
    "batalKategori" "BatalKategori",
    "batalAlasan" "BatalAlasan",
    "batalTanggal" TIMESTAMP(3),
    "refundStatus" "RefundStatus" NOT NULL DEFAULT 'NONE',
    "refundAmount" DECIMAL(65,30),
    "catatan" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "Deal_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "FollowUp" (
    "id" TEXT NOT NULL,
    "dealId" TEXT NOT NULL,
    "date" TIMESTAMP(3) NOT NULL,
    "note" TEXT NOT NULL,
    "statusLead" "StatusLead" NOT NULL,
    "actorId" TEXT NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "FollowUp_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "PipelineEvent" (
    "id" TEXT NOT NULL,
    "dealId" TEXT NOT NULL,
    "unitId" TEXT,
    "type" "EventType" NOT NULL,
    "eventDate" TIMESTAMP(3) NOT NULL,
    "fromStage" "Stage",
    "toStage" "Stage",
    "actorId" TEXT NOT NULL,
    "meta" JSONB,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "PipelineEvent_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "AuditLog" (
    "id" TEXT NOT NULL,
    "actorId" TEXT NOT NULL,
    "entity" TEXT NOT NULL,
    "entityId" TEXT NOT NULL,
    "action" TEXT NOT NULL,
    "before" JSONB,
    "after" JSONB,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "AuditLog_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "User_email_key" ON "User"("email");

-- CreateIndex
CREATE UNIQUE INDEX "User_salesId_key" ON "User"("salesId");

-- CreateIndex
CREATE UNIQUE INDEX "Project_code_key" ON "Project"("code");

-- CreateIndex
CREATE UNIQUE INDEX "Cluster_projectId_name_key" ON "Cluster"("projectId", "name");

-- CreateIndex
CREATE UNIQUE INDEX "Phase_clusterId_name_key" ON "Phase"("clusterId", "name");

-- CreateIndex
CREATE UNIQUE INDEX "UnitType_clusterId_name_key" ON "UnitType"("clusterId", "name");

-- CreateIndex
CREATE UNIQUE INDEX "Team_name_key" ON "Team"("name");

-- CreateIndex
CREATE UNIQUE INDEX "Sales_name_key" ON "Sales"("name");

-- CreateIndex
CREATE UNIQUE INDEX "Coordinator_name_key" ON "Coordinator"("name");

-- CreateIndex
CREATE UNIQUE INDEX "Agency_name_key" ON "Agency"("name");

-- CreateIndex
CREATE INDEX "RotationAssignment_agencyId_effectiveFrom_idx" ON "RotationAssignment"("agencyId", "effectiveFrom");

-- CreateIndex
CREATE UNIQUE INDEX "Bank_name_key" ON "Bank"("name");

-- CreateIndex
CREATE UNIQUE INDEX "Promo_name_key" ON "Promo"("name");

-- CreateIndex
CREATE UNIQUE INDEX "Target_scope_metric_period_teamId_key" ON "Target"("scope", "metric", "period", "teamId");

-- CreateIndex
CREATE UNIQUE INDEX "Unit_code_key" ON "Unit"("code");

-- CreateIndex
CREATE UNIQUE INDEX "Unit_currentDealId_key" ON "Unit"("currentDealId");

-- CreateIndex
CREATE INDEX "Unit_status_idx" ON "Unit"("status");

-- CreateIndex
CREATE INDEX "Deal_stage_idx" ON "Deal"("stage");

-- CreateIndex
CREATE INDEX "Deal_tanggalWi_idx" ON "Deal"("tanggalWi");

-- CreateIndex
CREATE INDEX "PipelineEvent_type_eventDate_idx" ON "PipelineEvent"("type", "eventDate");

-- CreateIndex
CREATE INDEX "PipelineEvent_dealId_idx" ON "PipelineEvent"("dealId");

-- AddForeignKey
ALTER TABLE "User" ADD CONSTRAINT "User_salesId_fkey" FOREIGN KEY ("salesId") REFERENCES "Sales"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Cluster" ADD CONSTRAINT "Cluster_projectId_fkey" FOREIGN KEY ("projectId") REFERENCES "Project"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Phase" ADD CONSTRAINT "Phase_clusterId_fkey" FOREIGN KEY ("clusterId") REFERENCES "Cluster"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "UnitType" ADD CONSTRAINT "UnitType_clusterId_fkey" FOREIGN KEY ("clusterId") REFERENCES "Cluster"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Sales" ADD CONSTRAINT "Sales_teamId_fkey" FOREIGN KEY ("teamId") REFERENCES "Team"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "RotationAssignment" ADD CONSTRAINT "RotationAssignment_agencyId_fkey" FOREIGN KEY ("agencyId") REFERENCES "Agency"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "RotationAssignment" ADD CONSTRAINT "RotationAssignment_coordinatorId_fkey" FOREIGN KEY ("coordinatorId") REFERENCES "Coordinator"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Target" ADD CONSTRAINT "Target_teamId_fkey" FOREIGN KEY ("teamId") REFERENCES "Team"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Unit" ADD CONSTRAINT "Unit_phaseId_fkey" FOREIGN KEY ("phaseId") REFERENCES "Phase"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Unit" ADD CONSTRAINT "Unit_unitTypeId_fkey" FOREIGN KEY ("unitTypeId") REFERENCES "UnitType"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Deal" ADD CONSTRAINT "Deal_agencyId_fkey" FOREIGN KEY ("agencyId") REFERENCES "Agency"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Deal" ADD CONSTRAINT "Deal_salesId_fkey" FOREIGN KEY ("salesId") REFERENCES "Sales"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Deal" ADD CONSTRAINT "Deal_coordinatorId_fkey" FOREIGN KEY ("coordinatorId") REFERENCES "Coordinator"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Deal" ADD CONSTRAINT "Deal_clusterId_fkey" FOREIGN KEY ("clusterId") REFERENCES "Cluster"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Deal" ADD CONSTRAINT "Deal_phaseId_fkey" FOREIGN KEY ("phaseId") REFERENCES "Phase"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Deal" ADD CONSTRAINT "Deal_unitTypeId_fkey" FOREIGN KEY ("unitTypeId") REFERENCES "UnitType"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Deal" ADD CONSTRAINT "Deal_unitId_fkey" FOREIGN KEY ("unitId") REFERENCES "Unit"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Deal" ADD CONSTRAINT "Deal_bankId_fkey" FOREIGN KEY ("bankId") REFERENCES "Bank"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "Deal" ADD CONSTRAINT "Deal_promoId_fkey" FOREIGN KEY ("promoId") REFERENCES "Promo"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "FollowUp" ADD CONSTRAINT "FollowUp_dealId_fkey" FOREIGN KEY ("dealId") REFERENCES "Deal"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "PipelineEvent" ADD CONSTRAINT "PipelineEvent_dealId_fkey" FOREIGN KEY ("dealId") REFERENCES "Deal"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

