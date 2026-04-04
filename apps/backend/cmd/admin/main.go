package main

import (
	"context"
	"fmt"
	"os"
	"text/tabwriter"
	"time"

	"github.com/joho/godotenv"
	"github.com/spf13/cobra"

	"github.com/arunprakashg/nimbus-backend/internal/config"
	"github.com/arunprakashg/nimbus-backend/internal/db"
	"github.com/arunprakashg/nimbus-backend/internal/license"
)

func main() {
	_ = godotenv.Load()

	root := &cobra.Command{
		Use:   "nimbus-admin",
		Short: "Nimbus license management CLI",
	}
	root.AddCommand(keyCmd(), productCmd(), customerCmd())

	if err := root.Execute(); err != nil {
		os.Exit(1)
	}
}

// ── shared DB init ─────────────────────────────────────────────────────────────

func mustDB() (*db.DB, func()) {
	cfg, err := config.Load()
	if err != nil {
		fmt.Fprintf(os.Stderr, "config: %v\n", err)
		os.Exit(1)
	}
	ctx := context.Background()
	database, err := db.New(ctx, cfg.DatabaseURL)
	if err != nil {
		fmt.Fprintf(os.Stderr, "database: %v\n", err)
		os.Exit(1)
	}
	return database, database.Close
}

// ── key commands ───────────────────────────────────────────────────────────────

func keyCmd() *cobra.Command {
	cmd := &cobra.Command{Use: "key", Short: "Manage license keys"}
	cmd.AddCommand(
		keyCreateCmd(),
		keyListCmd(),
		keyRevokeCmd(),
		keyExtendCmd(),
		keyActivationsCmd(),
	)
	return cmd
}

func keyCreateCmd() *cobra.Command {
	var email, product, tier string
	var activations, expiresIn int

	cmd := &cobra.Command{
		Use:   "create",
		Short: "Issue a new license key",
		RunE: func(cmd *cobra.Command, args []string) error {
			database, close := mustDB()
			defer close()

			ctx := context.Background()
			q := database.Pool()

			prod, err := db.GetProductBySlug(ctx, q, product)
			if err != nil {
				return fmt.Errorf("product %q not found", product)
			}

			customer, err := db.UpsertCustomer(ctx, q, email)
			if err != nil {
				return fmt.Errorf("upsert customer: %w", err)
			}

			key, err := license.GenerateKey("nmb_live")
			if err != nil {
				return fmt.Errorf("generate key: %w", err)
			}

			var expiresAt *time.Time
			if expiresIn > 0 {
				t := time.Now().AddDate(0, 0, expiresIn)
				expiresAt = &t
			}

			lic, err := db.CreateLicense(ctx, q, db.CreateLicenseParams{
				ProductID:      prod.ID,
				CustomerID:     customer.ID,
				Key:            key,
				Tier:           tier,
				MaxActivations: activations,
				ExpiresAt:      expiresAt,
			})
			if err != nil {
				return fmt.Errorf("create license: %w", err)
			}

			fmt.Printf("Key:       %s\n", lic.Key)
			fmt.Printf("Tier:      %s\n", lic.Tier)
			fmt.Printf("Slots:     %d\n", lic.MaxActivations)
			if lic.ExpiresAt != nil {
				fmt.Printf("Expires:   %s\n", lic.ExpiresAt.Format(time.DateOnly))
			} else {
				fmt.Printf("Expires:   never\n")
			}
			fmt.Printf("Customer:  %s\n", email)
			fmt.Printf("Product:   %s\n", product)
			return nil
		},
	}

	cmd.Flags().StringVar(&email, "email", "", "Customer email (required)")
	cmd.Flags().StringVar(&product, "product", "", "Product slug (required)")
	cmd.Flags().StringVar(&tier, "tier", "personal", "Tier: personal | pro | agency")
	cmd.Flags().IntVar(&activations, "activations", 1, "Max activations (-1 = unlimited)")
	cmd.Flags().IntVar(&expiresIn, "expires-in", 0, "Days until expiry (0 = never)")
	_ = cmd.MarkFlagRequired("email")
	_ = cmd.MarkFlagRequired("product")
	return cmd
}

func keyListCmd() *cobra.Command {
	var email string
	cmd := &cobra.Command{
		Use:   "list",
		Short: "List license keys",
		RunE: func(cmd *cobra.Command, args []string) error {
			database, close := mustDB()
			defer close()

			ctx := context.Background()
			var licenses []db.LicenseWithJoins
			var err error

			if email != "" {
				licenses, err = db.ListLicensesByEmail(ctx, database.Pool(), email)
			} else {
				licenses, err = db.ListAllLicenses(ctx, database.Pool())
			}
			if err != nil {
				return err
			}

			tw := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
			fmt.Fprintln(tw, "KEY\tTIER\tSTATUS\tSLOTS\tACTIVE\tEXPIRES\tCUSTOMER\tPRODUCT")
			for _, l := range licenses {
				exp := "never"
				if l.ExpiresAt != nil {
					exp = l.ExpiresAt.Format(time.DateOnly)
				}
				slots := fmt.Sprintf("%d", l.MaxActivations)
				if l.MaxActivations == -1 {
					slots = "∞"
				}
				fmt.Fprintf(tw, "%s\t%s\t%s\t%s\t%d\t%s\t%s\t%s\n",
					l.Key, l.Tier, l.Status, slots, l.ActiveDomains, exp, l.CustomerEmail, l.ProductSlug)
			}
			return tw.Flush()
		},
	}
	cmd.Flags().StringVar(&email, "email", "", "Filter by customer email")
	return cmd
}

func keyRevokeCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "revoke <key>",
		Short: "Revoke a license key",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			database, close := mustDB()
			defer close()
			if err := db.RevokeLicense(context.Background(), database.Pool(), args[0]); err != nil {
				return err
			}
			fmt.Printf("Revoked: %s\n", args[0])
			return nil
		},
	}
}

func keyExtendCmd() *cobra.Command {
	var days int
	cmd := &cobra.Command{
		Use:   "extend <key>",
		Short: "Extend a license key's expiry",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			database, close := mustDB()
			defer close()
			if err := db.ExtendLicense(context.Background(), database.Pool(), args[0], days); err != nil {
				return err
			}
			fmt.Printf("Extended %s by %d day(s)\n", args[0], days)
			return nil
		},
	}
	cmd.Flags().IntVar(&days, "by", 0, "Number of days to add (required)")
	_ = cmd.MarkFlagRequired("by")
	return cmd
}

func keyActivationsCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "activations <key>",
		Short: "List active domains for a key",
		Args:  cobra.ExactArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			database, close := mustDB()
			defer close()

			activations, err := db.ListActivations(context.Background(), database.Pool(), args[0])
			if err != nil {
				return err
			}
			if len(activations) == 0 {
				fmt.Println("No active domains.")
				return nil
			}

			tw := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
			fmt.Fprintln(tw, "DOMAIN\tACTIVATED\tLAST SEEN\tTOKEN ISSUED")
			for _, a := range activations {
				fmt.Fprintf(tw, "%s\t%s\t%s\t%s\n",
					a.Domain,
					a.ActivatedAt.Format(time.DateTime),
					a.LastSeenAt.Format(time.DateTime),
					a.TokenIssuedAt.Format(time.DateTime),
				)
			}
			return tw.Flush()
		},
	}
}

// ── product commands ───────────────────────────────────────────────────────────

func productCmd() *cobra.Command {
	cmd := &cobra.Command{Use: "product", Short: "Manage products"}
	cmd.AddCommand(productCreateCmd(), productListCmd())
	return cmd
}

func productCreateCmd() *cobra.Command {
	var slug, name string
	cmd := &cobra.Command{
		Use:   "create",
		Short: "Create a new product",
		RunE: func(cmd *cobra.Command, args []string) error {
			database, close := mustDB()
			defer close()
			p, err := db.CreateProduct(context.Background(), database.Pool(), slug, name)
			if err != nil {
				return err
			}
			fmt.Printf("Created product: %s (%s)\n", p.Name, p.Slug)
			return nil
		},
	}
	cmd.Flags().StringVar(&slug, "slug", "", "Unique slug (e.g. nimbus)")
	cmd.Flags().StringVar(&name, "name", "", "Display name")
	_ = cmd.MarkFlagRequired("slug")
	_ = cmd.MarkFlagRequired("name")
	return cmd
}

func productListCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "list",
		Short: "List products",
		RunE: func(cmd *cobra.Command, args []string) error {
			database, close := mustDB()
			defer close()
			products, err := db.ListProducts(context.Background(), database.Pool())
			if err != nil {
				return err
			}
			tw := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
			fmt.Fprintln(tw, "SLUG\tNAME\tCREATED")
			for _, p := range products {
				fmt.Fprintf(tw, "%s\t%s\t%s\n", p.Slug, p.Name, p.CreatedAt.Format(time.DateOnly))
			}
			return tw.Flush()
		},
	}
}

// ── customer commands ──────────────────────────────────────────────────────────

func customerCmd() *cobra.Command {
	cmd := &cobra.Command{Use: "customer", Short: "Manage customers"}
	cmd.AddCommand(customerListCmd())
	return cmd
}

func customerListCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "list",
		Short: "List all customers",
		RunE: func(cmd *cobra.Command, args []string) error {
			database, close := mustDB()
			defer close()
			customers, err := db.ListCustomers(context.Background(), database.Pool())
			if err != nil {
				return err
			}
			tw := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
			fmt.Fprintln(tw, "EMAIL\tNOTES\tCREATED")
			for _, c := range customers {
				fmt.Fprintf(tw, "%s\t%s\t%s\n", c.Email, c.Notes, c.CreatedAt.Format(time.DateOnly))
			}
			return tw.Flush()
		},
	}
}
