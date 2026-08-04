-- Organizations: policies and member suspension.
-- organization_policy is the read-model projection of an org's policies, the security bar every
-- member acting for the org must clear. One row per org, projected from the policy events, created
-- lazily the first time a policy is set: an org with no row has every policy inactive, so no
-- backfill for existing orgs is needed. Only enforceTwoFactor exists in this stage; the enforced
-- login method, allowed email domain and required GitHub orgs add their own columns when they land.
--
-- organization_member gains the suspension state produced by the compliance events
-- (member-policy-compliance-failed / -restored). A suspended member keeps their membership and
-- their teams; only their ability to act for the org is inert until they comply again.
--
-- Columns, index names and foreign keys match the Doctrine entity mappings so
-- doctrine:schema:update reports no changes. orgId is a plain ULID column, as on the other
-- organization projections.

CREATE TABLE organization_policy (
    orgId BINARY(16) NOT NULL,
    enforceTwoFactor TINYINT(1) NOT NULL DEFAULT 0,
    updatedAt DATETIME NOT NULL,
    allowedEmailDomains JSON NOT NULL,
    PRIMARY KEY (orgId)
) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB;

-- A JSON column takes no DEFAULT, so MySQL writes the JSON literal `null` into the existing rows; the UPDATE
-- normalises those to an empty array. Nobody is suspended at this point either way.
ALTER TABLE organization_member
    ADD suspended TINYINT(1) NOT NULL DEFAULT 0,
    ADD suspendedPolicies JSON NOT NULL;

UPDATE organization_member SET suspendedPolicies = JSON_ARRAY();
