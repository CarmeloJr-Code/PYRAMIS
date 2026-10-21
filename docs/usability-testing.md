# Usability Testing Protocol

The user testing `docs/evaluation.md` names as its first limitation. Its purpose is to establish the three things the system cannot evidence about itself — learnability, error recovery by a person, and perceived ease of use — and to score the six NFR-01 properties and the six aspects the implementation plan lists (navigation, clarity, learnability, task completion, error recovery, overall experience) from the only source that can: staff of Purple Yam Malaybalay using the live system.

Everything below runs on https://pyramis.onrender.com with the real accounts. It creates real records; see *Data* at the end.

## Participants

| Role in session | Who | Account | Device |
|---|---|---|---|
| Customer | Anyone who is not staff — a family member, a classmate | none | their own phone |
| Manager | The owner or the administrator | Administrator | laptop or desktop |
| Production Staff | The baker | Baker | a phone or tablet, as in the kitchen |
| Sales Staff | The cashier | Cashier | laptop or the counter device |

One participant per role is the minimum; two customers is better, since the storefront is the only screen the public touches. Participants must not have used the system before the session — that is the point.

## Method

Think-aloud, one participant at a time, one facilitator, one note-taker (the facilitator can do both with a phone recording audio, with the participant's consent).

- **No training.** The participant is told what the system is in two sentences, given the sign-in details, and handed the first task. Nothing is demonstrated.
- **The facilitator reads each task aloud** and does not help. If the participant is stuck for two minutes or asks for help, the facilitator gives the smallest hint that unblocks them and records an *assist*. A task completed with an assist is scored as such, not as a failure.
- **After each task**, the participant answers the Single Ease Question: *"Overall, how difficult or easy was that task?"* on 1 (very difficult) to 7 (very easy).
- **After all tasks**, the participant completes the post-session questionnaire below.
- **Time each task** from the end of the reading to the participant saying they are done. Timing is for the learnability comparison, not for judging the participant — say so.

Run the roles in the order given. The tasks build on each other: the manager's outlet is what the baker restocks, the customer's order is what the cashier completes.

Before the first participant, open the site once and wait for it to load — the free hosting plan spins the server down when idle and the first request can take a minute. Nobody should meet that as their first impression of the system.

## Per-task record

Copy one row per task into the note-taker's sheet.

| Field | Values |
|---|---|
| Task | e.g. C2 |
| Completed | Yes / With assist / No |
| Time | mm:ss |
| Wrong turns | Count of screens opened that were not on the path — a wrong turn the participant corrects on their own is a navigation finding, not a failure |
| Errors shown | Any validation message the system displayed, quoted |
| Recovered | For error-recovery tasks: did the participant fix the input and complete without help? Yes / No |
| SEQ | 1–7 |
| Notes | What they said, where they hesitated, what they expected that was not there |

---

## Scenarios

Tasks marked **(E)** are error-recovery tasks: the participant is asked to do something the system will refuse, and the finding is whether the refusal is understood and recovered from. Tasks marked **(L)** are the learnability pair — the same task done first-contact and again at the end of the session; the time difference is the measure.

### Customer — on a phone, no sign-in

*"Purple Yam Malaybalay takes pre-orders on its website. You want to order a cake for tomorrow."*

| # | Task as read to the participant | Path the system offers | What to watch |
|---|---|---|---|
| C1 | "Find out what sizes an Ube Cake comes in and what the Round one costs." | Home → See the menu → Ube Cake → *Sizes and prices* | Is the menu found without prompting? Is ₱500.00 for Round (7x3) read off correctly? |
| C2 (E) | "Order one Round Ube Cake to pick up tomorrow. Give your name, but skip the phone number and see what happens." | Add to order → *Your details* → Place pre-order → refused: *the customer phone field is required* | Does the participant understand the message and where to fix it? Is the rest of the form still filled in? |
| C3 | "Now fill in the phone number and place the order. Choose Main Branch for pickup." | Fill phone → pickup Main Branch → tomorrow's date → Place pre-order → *Keep this reference* | Note the order reference — the cashier needs it. Does the participant realise the reference matters? |
| C4 | "Close the site. Imagine it is later in the day — find out whether your order has been confirmed yet." | Reopen the confirmation page — from history, a bookmark, or by typing the address with the reference in it → status shows *Pending* | The confirmation page says *Keep this reference — it is how you check back on your order*, but the storefront has no form to type a reference into: the only way back is the page's own address. Did the participant keep it? If they cannot get back, stop after two minutes — that is the finding, not a failure of theirs, and it is the strongest candidate for a change to come out of this session. |

### Manager — first visit

*"You run the bakery. The system is new and empty. Set up tomorrow."*

| # | Task | Path | Watch |
|---|---|---|---|
| M1 | "Sign in. Tell me what you can see about the business right now." | employee/login → dashboard | First impression. Which cards do they read? Do they say what any number means? An empty dashboard on a first day is expected — do they understand why? |
| M2 | "The bakery is opening a stall at the mall. Add it as an outlet called *Mall Kiosk*." | Outlets → New outlet → save | Is *Outlets* found in the sidebar? The empty state says customers cannot pre-order until an outlet is open — Main Branch already is, so this is a second one. |
| M3 | "Kyle bakes tomorrow morning, six to ten. Put that on the roster." | Workforce → New shift → Save shift → open it → Assign someone → Kyle Baker | Two steps: the shift is saved first, then someone is assigned to it on its own page. Is that split clear, or do they look for an employee field on the shift form? |
| M4 (L) | "The Mall Kiosk needs six Round Ube Cakes tomorrow. Ask the kitchen for them." | Restocking → Schedule restock → Mall Kiosk, Ube Cake Round (7x3), 6 → *Requested* | Timed. The word *restock* — does it mean to the participant what the system means by it? |
| M5 | "Send Kyle a message telling him the restock is scheduled." | Messages → New conversation → Kyle Baker → send | Text only; watch for anyone looking for an attachment button. |

Sign the manager out; they return at the end.

### Production Staff — the baker

*"The kitchen is starting from an empty stockroom. Get ready to bake."*

| # | Task | Path | Watch |
|---|---|---|---|
| B1 | "Sign in. What is the manager asking you for today?" | dashboard (next shift, open restocks) or Messages | Where do they look first — the dashboard, the roster, or the chat? All three lead there. |
| B2 | "A delivery just arrived: 10 kg of All-Purpose Flour, 5 kg of White Sugar, 30 Extra Large Eggs. Record it." | Inventory → open each ingredient → Record movement → receipt | Three receipts. Does the third go faster than the first? Is it clear that stock is counted in purchasing units (kg, pieces), not recipe measures? |
| B3 (E) | "Log a bake of four Round Ube Cakes." | Production → Log a run → the page says *No size has a recipe with ingredients in it* and offers *Write a recipe* | Nothing can be logged yet. Does the participant follow the link the message offers, or go looking on their own? |
| B4 | "Write the recipe for a Round Ube Cake: 1 kg of All-Purpose Flour, half a kilo of White Sugar, and 3 Extra Large Eggs per cake." | Production → Recipes → Ube Cake Round (7x3) → Add ingredient (three times) → Save recipe | The most form-heavy task. Adding lines, entering 0.5, saving. |
| B5 (E) | "Log a bake of forty Round Ube Cakes." | Log a run → Ube Cake Round (7x3), 40 → *This run will use 40 kg …* → Log run → refused: *Only 10 kg of All-Purpose Flour in stock.* | Does the participant read *This run will use* before pressing, or only the refusal after? Is the message understood as a stock problem rather than a form problem? |
| B6 | "Log the bake you actually did: four." | quantity 4 → *This run will use 4 kg, 2 kg, 12 pc* → Log run → the run appears in the day list | Do they change the one number, or start over? Does the run appear where they expect? |
| B7 (E) | "The Mall Kiosk asked for six. Set them aside — but say you set aside eight." | Restocking → open the request → Start preparing → prepared 8 → refused: *Only 4 at the main branch.* | The shelf holds four (from B6). Is the refusal understood as "you only baked four"? |
| B8 | "Set aside what you actually have." | prepared 4 → Save prepared quantities → *Deliver to outlet* appears | Does the participant understand that recording a short bake as short is the intended behaviour? |

### Sales Staff — the cashier

*"You are on the counter at the main branch. A customer's order came in overnight."*

| # | Task | Path | Watch |
|---|---|---|---|
| S1 (E) | "Sign in. Get your password wrong the first time." | employee/login → wrong password → *These credentials do not match our records* → correct password → dashboard | Is the message clear that it was the password, not the email? |
| S2 | "Find the order placed by *[customer's name from C3]* and tell me what they want and when." | Orders → open queue → open the order | Is the open queue understood as "today's work"? |
| S3 | "The kitchen has confirmed it. Move the order along as the day goes — confirmed, then being prepared, then ready. Tell me when the customer can be told to come." | Mark as Confirmed → Preparing → Ready for pickup | Three presses. Do the status names match how the participant talks about an order? |
| S4 | "The customer has arrived and paid. Finish the order." | Mark as Completed → *can no longer be changed* | Does the participant look for a separate "record the sale" step? There isn't one — the sale is made from the order. Note whether they check. |
| S5 | "Check that the money shows up in today's sales." | Sales → the sale with the order's reference, ₱500.00 | Is the link between order and sale obvious? |
| S6 | "A walk-in buys two Ube Custard Cake slices. Record it." | Sales → Record counter sale → Add item → Ube Custard Cake Slice × 2 → Record sale → ₱140.00 | The sale form with no order behind it. |
| S7 | "You paid ₱350 cash for packaging at the market this morning. Record it as an expense." | Expenses → Record expense → Packaging, 350 | Is the category list understood? |
| S8 (E) | "Check how much flour is left in the stockroom." | Inventory is not in the sidebar; typing /employee/inventory → 403 | The participant cannot do this — inventory is not a cashier's. The finding is whether they realise it is not theirs (and who to ask) or keep hunting. Stop after two minutes and explain. |

### Manager — return visit

*"End of the day."*

| # | Task | Path | Watch |
|---|---|---|---|
| M6 | "The kiosk restock is packed. Send it." | Restocking → open → Deliver to outlet → *Delivered* | Does the four-of-six shortfall show, and is it understood? |
| M7 | "How much did the bakery take today, and what did it spend?" | Reports → Sales (₱640.00) and Expenses (₱350.00) | Two reports or the dashboard — either is right. Which do they choose? |
| M8 | "What does the system think you should bake more of?" | Forecast → *Demand outlook* → the AI reading | Do they read *An estimate, not a prediction*? Ask afterwards: "Would you act on that? How much would you trust it?" Record the answer verbatim — it is the only evidence on BR-008 in use that exists. |
| M9 (L) | "The kiosk wants four Chocolate Cake Tincan Rounds on Saturday. Ask the kitchen." | Restocking → Schedule restock → … | Timed. Compare with M4. A large drop is learnability; no drop with a low SEQ is a finding. |
| M10 | "Kyle's shift tomorrow moves to seven o'clock. Change it." | Workforce → the shift → edit | Editing rather than creating. |

---

## Post-session questionnaire

Given on paper or a form, after the last task, before any debrief conversation.

**Part A — System Usability Scale.** The standard ten statements, each rated 1 (strongly disagree) to 5 (strongly agree). Score in the standard way: odd items contribute (rating − 1), even items contribute (5 − rating), sum × 2.5 gives 0–100. A score of 68 is the published average; report each participant's score and the mean.

1. I think that I would like to use this system frequently.
2. I found the system unnecessarily complex.
3. I thought the system was easy to use.
4. I think that I would need the support of a technical person to be able to use this system.
5. I found the various functions in this system were well integrated.
6. I thought there was too much inconsistency in this system.
7. I would imagine that most people would learn to use this system very quickly.
8. I found the system very cumbersome to use.
9. I felt very confident using the system.
10. I needed to learn a lot of things before I could get going with this system.

**Part B — NFR-01 properties.** Each rated 1 (strongly disagree) to 5 (strongly agree). These are the six properties the requirement names, so the scores go straight into the evaluation's usability table.

| | Statement |
|---|---|
| N1 Clear navigation | I could find where to go without being told. |
| N2 Role-specific dashboard | The first screen after sign-in showed me what mattered for my job. |
| N3 Consistent components | Screens that did similar things looked and worked the same way. |
| N4 Understandable labels | The words on buttons, menus and messages meant what I expected. |
| N5 Responsive interface | It worked properly on the device I used. |
| N6 Straightforward workflows | Finishing a task took the steps I expected and no more. |

**Part C — open questions.** Recorded verbatim.

1. What was the hardest thing you were asked to do, and what made it hard?
2. Was there a moment you did not know what to do next? What were you looking for?
3. What would you change first?
4. *(Manager only)* Would you act on the forecast reading? Why or why not?

---

## Mapping results to the evaluation

| Evaluation aspect | Source in this protocol |
|---|---|
| Ease of navigation | Wrong-turn counts across all tasks; N1 rating; SUS 2, 8 |
| Clarity of interface | N3, N4 ratings; quoted confusions in task notes; open question 2 |
| Learnability | M4→M9 and B2's three receipts, timed; SUS 4, 7, 10; assist count on first-contact tasks (C1, M1, B1, S1) |
| Task completion | Completed / assisted / failed per task, all 30 tasks |
| Error recovery | Recovered Yes/No on the six **(E)** tasks; whether the offered link was followed in B3 |
| Overall experience | SUS total; N1–N6 mean; open question 3 |

Report the findings as a new section of `docs/evaluation.md` — *Usability, from user testing* — with the per-task table, the SUS and Part B scores per participant, and the quoted answers. Anything a participant could not do unaided is a finding; anything two participants could not do is a defect.

## Data

The session writes real records to the live database: one outlet, one shift, one recipe, three receipts, a production run, two restocks, one order, two sales, one expense, one message. Put *UT* in the notes field wherever a form has one, so the records can be recognised afterwards.

None of this needs to be undone — it is one honest day of activity, and the sales and inventory reports are meant to hold it. If the business would rather start clean: the order can be cancelled, the expense deleted, and the outlet renamed to a real one; sales, movements and runs are ledgers and stay, which is by design (BR-004).

Do not run this against a development database instead. The cold start, the phone, the real Groq reading and the real account are the conditions being evaluated.

## Source

`docs/evaluation.md` limitation 1; PRD NFR-01; Vertical-Slice Implementation Plan §19 (usability aspects); the four browser journeys in `tests/Browser/`, which these scenarios extend. SUS: Brooke, J. (1996), *SUS: A quick and dirty usability scale*.
