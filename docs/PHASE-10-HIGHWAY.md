# Phase 10: Content Management Pro - Highway Document

**Version:** 1.0  
**Date:** 2026-08-08  
**Status:** RFC - Request for Comments  
**Target Release:** v2.6 (Q4 2026)  
**Owner:** Product & Engineering Leadership

---

## Purpose

This highway document provides the **strategic overview and execution roadmap** for Phase 10: Content Management Pro. It translates the detailed PRD into an actionable plan with clear milestones, dependencies, and decision gates.

**Audience:** Engineering leads, product managers, stakeholders who need the big picture without implementation details.

---

## The Big Picture

### What We're Building

Three interconnected capabilities that transform Saltus from a CPT builder into a complete content platform:

1. **Content Relationships** - Connect posts to other posts (movies ↔ actors, projects ↔ team members)
2. **Workflow Engine** - Custom approval states beyond draft/publish (Legal Review → Approved → Published)
3. **Scheduled Actions** - Automated publish/archive operations (embargoes, expirations)

### Why Now

**Market signal:** ACF Pro generates ~$5M/yr on relationship fields alone. Developers complain about:
- UI-configured fields that don't version control
- Sync issues between environments
- No type safety or IDE autocomplete
- No AI/MCP integration

**Strategic position:** We're already AI-native (MCP/WebMCP), code-first, and type-safe. Adding relationships makes us the developer-first alternative to ACF Pro.

**Competitive window:** ACF hasn't shipped major features in 2 years. Toolset is stagnant. Pods community is dying. **Now is the time.**

### Success Looks Like

**6 months post-launch:**
- 40% of Saltus sites use relationships
- 15% use custom workflows
- 30% use scheduled actions
- GitHub stars: +600 (from ~200 to ~800)
- 5+ case studies from real projects
- WordPress Tavern coverage: "Saltus challenges ACF dominance"

---

## Strategic Rationale

### Jobs to Be Done

When developers choose Saltus, they're hiring it to:

1. **"Stop writing boilerplate CPT code"** ✅ We do this today
2. **"Model my domain without raw meta fields"** ❌ We fail at this
3. **"Version control my content structure"** ✅ We do this today
4. **"Build AI-powered content tools"** ✅ We're best in class
5. **"Enforce editorial workflows"** ❌ Phase 10 unlocks this
6. **"Automate content operations"** ❌ Phase 10 unlocks this

Phase 10 addresses jobs #2, #5, and #6 — the biggest gaps preventing adoption for complex projects.

### Market Positioning

**Before Phase 10:**  
*"Saltus is a lightweight CPT framework for simple plugins."*

**After Phase 10:**  
*"Saltus is the developer-first platform for building WordPress applications — code-configured, Git-friendly, AI-native, and free."*

### Competitive Moat

After Phase 10, we'll be the only solution with:

- ✅ Code-configured relationships (version control friendly)
- ✅ Type-safe queries with eager loading
- ✅ AI-native relationship discovery (MCP/WebMCP)
- ✅ Custom workflows with capability gating
- ✅ Scheduled actions with reliability monitoring
- ✅ 100% free and open source

**Switching cost:** ACF/Toolset users have data in wp_postmeta. We need migration tools (Phase 10.5, planned).

---

## Architecture Overview

### High-Level Design Principles

1. **Opt-in, not breaking** - Existing Saltus sites continue working
2. **Performance-first** - Target <100ms p95 for relationship queries
3. **Git-friendly** - Everything in code, nothing in wp_options
4. **AI-native** - MCP/WebMCP tools understand relationships
5. **WordPress-compatible** - Works with WP_Query, get_posts(), etc.

### Data Model

```
┌─────────────────────────────────────────────────────────────┐
│                    WordPress Core Tables                     │
│  wp_posts (unchanged) + wp_postmeta (unchanged)             │
└─────────────────────────────────────────────────────────────┘
                            ▲
                            │
┌───────────────────────────┴─────────────────────────────────┐
│               New: wp_saltus_relationships                   │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ relationship_key | from_post_id | to_post_id | ...  │   │
│  │ movie_actors     | 123          | 456         | ... │   │
│  │ movie_director   | 123          | 789         | ... │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                            ▲
                            │
┌───────────────────────────┴─────────────────────────────────┐
│                  Saltus Relationship API                     │
│  Relations::for('movie', 123)->with('actors')->get()        │
└─────────────────────────────────────────────────────────────┘
```

**Why a custom table?**
- Performance: Indexed for joins (10x faster than meta queries)
- Pivot data: Many-to-many metadata (actor's role in movie)
- Ordering: Explicit order_index for has_many
- Queries: Simpler SQL, easier to optimize

### Integration Points

```
Model Config (YAML)
  ↓
Relationship Registry
  ↓
  ├─→ Admin Metaboxes (Select2 UI)
  ├─→ REST API (CRUD endpoints)
  ├─→ MCP Tools (AI discovery)
  ├─→ WebMCP Tools (browser agents)
  ├─→ WP-CLI Commands (bulk operations)
  └─→ Query Builder (eager loading)
```

---

## Execution Plan

### Phase Structure

Phase 10 is split into 3 sub-phases with incremental value delivery:

| Sub-Phase | Duration | Deliverable | Value |
|-----------|----------|-------------|-------|
| **10A** Relationships | 8 weeks | Connect posts, query efficiently, admin UI | Core foundation |
| **10B** Workflows | 6 weeks | Custom states, transitions, approvals | Editorial control |
| **10C** Scheduled Actions | 4 weeks | Publish/archive automation | Operational efficiency |

**Total timeline: 18 weeks** (Oct - Jan 2027, assuming Q4 2026 start)

### Milestone Roadmap

```
Week 1-4: Alpha (10A Core)
├─ Database schema + migrations
├─ Relationship CRUD operations
├─ Query builder with eager loading
└─ Internal dogfooding (3 projects)

Week 5-8: Alpha (10A Complete)
├─ Admin metabox UI (Select2)
├─ REST API endpoints
├─ MCP/WebMCP tools
└─ Beta release to 50 users

Week 9-11: Beta (10B Workflows)
├─ Workflow state registry
├─ Transition validation
├─ Dashboard UI
└─ Notification system

Week 12-14: Beta (10C Scheduled Actions)
├─ Scheduled publish/unpublish
├─ Auto-archival rules
├─ Monitoring dashboard
└─ Release Candidate

Week 15: General Availability
├─ v2.6.0 release
├─ Launch blog post
├─ Documentation complete
└─ Community outreach

Week 16-18: Iterate
├─ Monitor adoption metrics
├─ Fix top 5 issues
├─ Performance optimization
└─ Retroactive: Post-launch retrospective
```

### Decision Gates

**Gate 1: After Week 4 (Alpha Core)**
- **Question:** Is the relationship query performance acceptable?
- **Criteria:** <100ms p95, <3 queries for post + 2 relationships
- **Go:** Proceed to UI/API
- **No-Go:** Optimize schema, consider alternative approaches

**Gate 2: After Week 8 (Beta Release)**
- **Question:** Do beta users understand the API?
- **Criteria:** 70% say "would recommend", <10 critical bugs
- **Go:** Build workflows
- **No-Go:** Iterate on API design

**Gate 3: After Week 14 (Release Candidate)**
- **Question:** Is this production-ready?
- **Criteria:** 85% test coverage, docs complete, zero P0 bugs
- **Go:** Launch
- **No-Go:** Delay 2 weeks, address blockers

---

## Resource Allocation

### Team Composition

| Role | Allocation | Weeks | Responsibilities |
|------|------------|-------|------------------|
| **Lead Engineer** | 100% | 18 | Architecture, code review, final say on design |
| **Backend Engineer** | 100% | 12 | Relationships (8w) + Workflows (4w) |
| **Frontend Engineer** | 50% | 12 | Metabox UI (4w) + Dashboards (4w) + Polish (4w) |
| **QA Engineer** | 25% | 18 | Test automation, beta coordination |
| **Product Designer** | 10% | 18 | UI mockups (2w equivalent) |
| **Technical Writer** | 25% | 16 | Guides, API docs, videos (4w equivalent) |

**Total effort:** ~42 person-weeks

### Budget

| Category | Cost | Notes |
|----------|------|-------|
| **Engineering** | $120K | 42 person-weeks @ ~$3K/week blended rate |
| **Infrastructure** | $2K | Testing environments, CI/CD |
| **Marketing** | $5K | Post Status sponsorship, video production |
| **Beta Program** | $3K | Swag, incentives for 50 beta users |
| **Contingency (15%)** | $19.5K | Buffer for unknowns |
| **Total** | **$149.5K** | |

### External Dependencies

| Dependency | Risk | Mitigation |
|------------|------|------------|
| WordPress 7.x changes | Low | Monitor WP core trac, adapt if needed |
| Action Scheduler API | Low | Fallback to WP-Cron if not available |
| Select2 library | Low | Bundled, no external CDN dependency |
| Beta user availability | Medium | Over-recruit (70 invites for 50 participants) |

---

## Key Design Decisions

### Decision 1: Custom Table vs wp_postmeta

**Options:**
- A) Use wp_postmeta (WP-native, no migrations)
- B) Custom table (better performance, more complex)

**Decision:** Custom table  
**Rationale:**
- Performance: 10x faster joins with proper indexes
- Pivot data: Many-to-many needs metadata (actor's role)
- Ordering: has_many needs explicit order_index
- Proven pattern: Similar to wp_term_relationships

**Trade-offs accepted:**
- Requires migration system
- Developers must run migrations on updates
- Slightly more complex backup/restore

---

### Decision 2: API Design Philosophy

**Options:**
- A) Laravel Eloquent-style (elegant, learning curve)
- B) WordPress-style (familiar, more verbose)
- C) Custom DSL (flexible, more to learn)

**Decision:** Laravel-inspired, WordPress-adapted  
**Rationale:**
- Many WordPress devs know Laravel
- Eloquent patterns are battle-tested
- "Inspired by" gives us flexibility to adapt

**Example:**
```php
// Eloquent-inspired
$movie->with('actors')->get();

// But adapted for WordPress
Relations::for('movie', 123)->with('actors')->get();
// Returns WP_Post objects, not models
```

**Trade-offs accepted:**
- Not 100% Eloquent (confuse strict Laravel devs)
- Not 100% WordPress (feels "foreign" to WP purists)
- Middle ground serves both audiences

---

### Decision 3: Workflow Storage

**Options:**
- A) Custom post_status values (WP-native)
- B) Postmeta _saltus_workflow_state (flexible)
- C) Custom table (overkill)

**Decision:** Hybrid - post_status for simple, postmeta for complex  
**Rationale:**
- WP core queries still work (post_status=publish)
- Custom states in meta don't break core
- Admin UI can hide complexity

**Trade-offs accepted:**
- Slight inconsistency (some states in core, some in meta)
- Developers must use Saltus query helpers for custom states

---

### Decision 4: Scheduled Actions Backend

**Options:**
- A) WP-Cron only (built-in, unreliable)
- B) Action Scheduler required (reliable, dependency)
- C) Both with auto-detection (complex, best UX)

**Decision:** Both with auto-detection  
**Rationale:**
- Action Scheduler is gold standard (WooCommerce uses it)
- But can't require it (adds dependency)
- Auto-detect gives best experience

**Implementation:**
```php
if (function_exists('as_schedule_single_action')) {
    // Use Action Scheduler
} else {
    // Fallback to WP-Cron + reliability checks
}
```

**Trade-offs accepted:**
- More testing surface (2 backends)
- Slightly lower reliability for WP-Cron users

---

## Risk Register

### High-Impact Risks

| Risk | Probability | Impact | Mitigation | Owner |
|------|-------------|--------|------------|-------|
| **Performance degrades with 100K+ posts** | Medium | High | Early load testing, query optimization, document scale limits | Lead Engineer |
| **Beta users don't understand API** | Medium | High | Multiple rounds of feedback, improve docs, add examples | Product Manager |
| **Migration failures cause data loss** | Low | Critical | Dry-run mode, automated backups, rollback support | Backend Engineer |
| **Conflicts with ACF/Toolset on same site** | Medium | Medium | Namespace properly, test compatibility, document limitations | QA Engineer |

### Medium-Impact Risks

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| **Metabox UI sluggish with 10K+ items** | Medium | Medium | Virtualized lists, lazy loading, pagination |
| **WP-Cron misses scheduled actions** | High | Medium | Fallback checks, admin notices, monitoring |
| **Breaking changes in WordPress 7.x** | Low | Medium | Monitor core development, beta test against WP nightlies |
| **Low adoption (miss 40% target)** | Medium | Medium | Invest in marketing, case studies, tutorial content |

### Known Unknowns

- Will WordPress 7.1+ change the Abilities API? (Monitor monthly)
- Will Chrome ship WebMCP in 157 as planned? (Impacts WebMCP tools)
- Will AI agent traffic materialize? (Doesn't block launch, but affects prioritization)

---

## Success Metrics

### North Star

**% of Saltus sites using 2+ related models**  
Current: 0% → Target: 40% by 6 months post-launch

### Leading Indicators (Track Weekly)

| Metric | Week 8 | Week 15 | Month 3 | Month 6 |
|--------|--------|---------|---------|---------|
| Beta signups | 50 | - | - | - |
| v2.6 installs | - | 200 | 800 | 2000 |
| Sites with relationships | 5 | 20 | 200 | 800 |
| GitHub stars | +10 | +50 | +200 | +600 |
| Support tickets/week | 5 | 10 | 8 | 5 |
| Doc page views | 200 | 1000 | 3000 | 6000 |

### Lagging Indicators (Track Monthly)

| Metric | Month 3 | Month 6 | Month 12 |
|--------|---------|---------|----------|
| Sites using workflows | 10% | 15% | 25% |
| Sites using scheduled actions | 20% | 30% | 40% |
| Community case studies | 1 | 3 | 10 |
| WordPress.org rating | 4.5 | 4.7 | 4.8 |
| Premium support contracts | 2 | 5 | 15 |

### Quality Gates

| Metric | Target |
|--------|--------|
| Test coverage | >85% |
| PHPStan level | 7 (maintained) |
| Relationship query p95 | <100ms |
| Scheduled action reliability | >99.9% |
| Zero critical bugs at launch | Required |
| Documentation completeness | >95% |

---

## Go-to-Market Strategy

### Pre-Launch (Weeks 1-14)

**Build anticipation:**
- Weekly dev blog posts on progress
- Preview videos showing relationships in action
- Engage GitHub community (issues, discussions)
- Recruit 5 launch partners for case studies

**Targets:**
- 500 blog post views
- 50 beta signups
- 3 launch partner commitments

### Launch Week (Week 15)

**Day 1 (Monday):**
- Release v2.6.0
- Publish launch blog post
- Email 2,000 newsletter subscribers
- Submit to WordPress Tavern

**Day 2 (Tuesday):**
- Tweet thread (10 tweets showing examples)
- Reddit r/ProWordPress announcement
- Dev.to technical deep-dive

**Day 3 (Wednesday):**
- Post Status newsletter sponsorship (reaches 5,000)
- Hacker News "Show HN" post

**Day 4-5:**
- Monitor support channels
- Respond to feedback
- Fix critical bugs if any

**Week 1 targets:**
- 200 v2.6 installs
- 50+ GitHub stars
- WordPress Tavern coverage
- 10+ organic discussions

### Post-Launch (Weeks 16-20)

**Content marketing:**
- Video tutorial series (10 episodes)
- WP Minute podcast interview
- Guest posts on major WP blogs
- Case study series (1 per week)

**Community engagement:**
- Host AMA on Reddit
- Speak at WordCamp (submit 3 talks)
- Create Slack/Discord for power users

**Month 3 targets:**
- 800 v2.6 installs
- 200+ GitHub stars
- 3 published case studies

---

## Documentation Plan

### Tier 1: Quick Start (Day 1 Experience)

**Goal:** Get a developer from zero to working relationship in 5 minutes.

**Content:**
```markdown
# Your First Relationship

Step 1: Define two models (Movie, Person)
Step 2: Add relationship config to Movie
Step 3: Query in your template
Step 4: See it in admin

[5-minute video walkthrough]
```

### Tier 2: Cookbook (Common Patterns)

**Recipes:**
1. Team Directory (people ↔ departments ↔ projects)
2. E-commerce (products ↔ categories ↔ brands)
3. Real Estate (properties ↔ agents ↔ agencies)
4. News Site (articles ↔ authors ↔ topics)
5. Event Platform (events ↔ venues ↔ speakers)
6. Editorial Workflow (3-state approval process)
7. Scheduled Embargoes (press releases)
8. Auto-Archival (job listings, time-sensitive content)

Each recipe: Problem → Solution → Code → Result

### Tier 3: API Reference (Deep Dive)

**Auto-generated from PHPDoc:**
- Every relationship method documented
- Performance characteristics noted
- Code examples for every method
- Migration guides from ACF/Toolset

**Sections:**
- Relationship Types (HasOne, HasMany, BelongsTo, ManyToMany)
- Query Builder API
- REST API Reference
- MCP/WebMCP Tools
- Workflow Configuration
- Scheduled Actions

### Tier 4: Video Tutorials

**Series: "Building with Saltus Relationships"**
1. Introduction (5 min)
2. Your First Relationship (10 min)
3. Many-to-Many with Pivot Data (12 min)
4. Querying Relationships Efficiently (15 min)
5. Building a Team Directory (20 min)
6. Custom Workflows (15 min)
7. Scheduled Publishing (10 min)
8. Migration from ACF (18 min)
9. Advanced Patterns (20 min)
10. Performance Optimization (15 min)

**Total: 140 minutes of content**

---

## Post-Launch Iteration Plan

### Month 1: Stabilize

**Focus:** Fix critical issues, address adoption barriers

**Activities:**
- Daily monitoring of GitHub issues
- Weekly office hours for beta users
- Hot-fix releases for critical bugs
- Expand documentation based on support tickets

**Success = No critical bugs, <5 support tickets/day**

### Month 2: Optimize

**Focus:** Performance and UX improvements

**Activities:**
- Profile real-world usage (slow queries, memory)
- Optimize metabox UI for large datasets
- Add missing documentation sections
- Gather feature requests

**Success = p95 query times meet targets**

### Month 3: Expand

**Focus:** Drive adoption

**Activities:**
- Publish 3 case studies
- Record remaining tutorial videos
- Submit WordCamp talks
- Build ACF migration tool (Phase 10.5)

**Success = 200 sites using relationships**

### Month 4-6: Scale

**Focus:** Hit 40% adoption target

**Activities:**
- Launch partner program (agencies)
- Premium support offering ($2K/yr)
- Custom relationship development service ($5K)
- Community showcase (best projects)

**Success = 800 sites using relationships (40% of 2,000 active sites)**

---

## Open Questions & Decisions Needed

### Pre-Development (Week 1)

**Q1: Should we support polymorphic relationships?**
- Example: Comment → (Post OR Page OR Movie)
- **Recommendation:** No for v2.6, evaluate for v2.7
- **Decision maker:** Lead Engineer + Product Manager
- **Deadline:** Week 1

**Q2: Should relationship metaboxes auto-generate on both sides?**
- Example: Edit Actor → see all Movies automatically
- **Recommendation:** Yes, with opt-out flag
- **Decision maker:** Product Designer + Lead Engineer
- **Deadline:** Week 2

### Mid-Development (Week 8)

**Q3: Should we build a relationship field "calculator"?**
- Example: total_budget = sum(related_expenses.amount)
- **Recommendation:** Not for v2.6, too complex
- **Decision maker:** Product Manager
- **Deadline:** Week 8 (based on beta feedback)

**Q4: Should workflow notifications integrate with Slack/Teams?**
- **Recommendation:** Core uses WP emails, provide filter for extensions
- **Decision maker:** Lead Engineer
- **Deadline:** Week 10

### Pre-Launch (Week 14)

**Q5: Should we delay for Action Scheduler integration?**
- If we can't achieve 99.9% WP-Cron reliability
- **Recommendation:** Ship with clear docs on Action Scheduler benefits
- **Decision maker:** Product Manager + Engineering Lead
- **Deadline:** Week 14 (based on testing results)

---

## Stakeholder Communication Plan

### Weekly Engineering Sync (Fridays)

**Attendees:** Engineering team  
**Format:** 30 min standup  
**Topics:**
- Progress vs roadmap
- Blockers
- Next week priorities
- Technical decisions needed

**Output:** Slack update to #saltus-dev

### Bi-Weekly Stakeholder Update (Mondays)

**Attendees:** Engineering + Product + Leadership  
**Format:** 45 min review  
**Topics:**
- Milestone progress
- Metrics review
- Risk assessment
- Go/No-Go decisions

**Output:** Email update to stakeholders

### Monthly Community Update

**Audience:** GitHub watchers, newsletter subscribers  
**Format:** Blog post + video  
**Topics:**
- What we shipped
- What's coming next
- How to get involved
- Spotlight: community contributions

**Output:** Blog + newsletter + social media

---

## Phase 10.5: Migration Tools (Optional)

**If Phase 10 succeeds, fund this 2-week follow-up:**

### ACF Relationship Field Importer

**Goal:** One-click migration from ACF to Saltus relationships

**Features:**
- Detect ACF relationship fields
- Map to Saltus relationship config
- Migrate data from postmeta to wp_saltus_relationships
- Preserve all connections
- Dry-run mode with preview

**Impact:** Removes biggest switching cost from ACF

**Effort:** 2 weeks (1 engineer)

**ROI:** Could increase adoption by 20%+ (many sites use ACF)

---

## Conclusion & Next Steps

### Why Phase 10 Matters

Phase 10 is the most important release since v2.0. It's not just features — it's a **strategic repositioning** from "nice CPT framework" to "serious application platform."

**The market is ready:** ACF's dominance is shakeable. Developers want code-first tools.

**The tech is ready:** We have the foundation (MCP, REST, WP-CLI, WebMCP). Relationships are the missing piece.

**The timing is right:** Competitors are stagnant. WordPress 7.x creates momentum. AI coding agents make developer-first tools more valuable.

### The Ask

**Leadership approval for:**
1. **Budget:** $149.5K (engineering + marketing)
2. **Timeline:** 18 weeks (Oct 2026 - Jan 2027)
3. **Resources:** 2 full-time engineers + support roles
4. **Risk tolerance:** Accept some complexity for strategic value

### Immediate Next Steps (Week 1)

**Monday:**
- [ ] Stakeholder meeting: Review this document
- [ ] Decision: Approve budget and timeline
- [ ] Assign: Lead Engineer + Backend Engineer

**Tuesday:**
- [ ] Kick-off meeting with full team
- [ ] Create project in GitHub (milestone, labels)
- [ ] Set up dev environment

**Wednesday:**
- [ ] Technical spike: Database schema prototype
- [ ] Design: Initial metabox UI mockups
- [ ] Docs: Outline relationship guide structure

**Thursday:**
- [ ] Review spike results
- [ ] Finalize DB schema approach
- [ ] Update ROADMAP.md with Phase 10 details

**Friday:**
- [ ] First code: Create migration file
- [ ] First test: Relationship CRUD operations
- [ ] First doc: API design examples
- [ ] Weekly sync: Report progress

### Long-Term Vision

Phase 10 is not the end — it's the foundation for:

- **Phase 11:** Security & Compliance (field-level permissions, encryption, GDPR)
- **Phase 12:** Developer Experience (validation, migrations, GraphQL)
- **Phase 13:** Enhanced UX (inline editing, bulk operations, frontend forms)
- **Phase 14:** Observability (metrics dashboard, error tracking, profiling)

**But first, we ship relationships.**

---

**Document Status:** Ready for review  
**Next Review:** After stakeholder feedback  
**Owner:** Product Manager  
**Contributors:** Lead Engineer, Engineering Leadership

---

*Let's build something developers love. Ship fast. Learn faster.*
